<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Streaming AES-256-GCM for backup archive members.
 *
 * Nothing is ever loaded whole: attachments have no size cap and memory_limit is
 * commonly 256M, so both directions work one chunk at a time. Peak usage stays
 * around 2x the chunk size regardless of file size.
 *
 * =============================================================================
 * WIRE FORMAT - "FTNCBK01"   (CONTRACT: Phase 5's standalone decrypt tool
 *                             reimplements this from this docblock alone.
 *                             Changing anything here changes Phase 5 too.)
 * =============================================================================
 *
 * All integers are BIG-ENDIAN and unsigned.
 *
 *   HEADER (20 bytes, exactly once, at offset 0)
 *     magic_version : 8 bytes  ASCII "FTNCBK01"
 *     chunk_size    : uint32   plaintext bytes per full data record
 *                              (informational: readers MUST use each record's
 *                              own ct_len and MUST NOT rely on this value)
 *     nonce_prefix  : 8 bytes  random, generated once per stream
 *
 *   RECORD (repeats 1..N times, immediately after the header, in order)
 *     ct_len : uint32     byte length of the ct field below
 *     nonce  : 12 bytes   = nonce_prefix[8] || counter[4]
 *     ct     : ct_len bytes = AES-256-GCM ciphertext || 16-byte GCM tag
 *                             (tag APPENDED, "combined mode" - this is what
 *                              WebCrypto and Python cryptography's AESGCM expect)
 *
 *   nonce_prefix  The SAME 8 bytes in the header and in every record of that
 *                 stream. Readers MUST derive the expected nonce from the
 *                 HEADER prefix and reject any record whose stored nonce
 *                 differs from nonce_prefix || counter.
 *   counter       uint32, starts at 0 for the first record and increments by 1
 *                 per record. Caps a stream at 2^32 records.
 *
 *   AAD (25 bytes) authenticated with every record, never stored:
 *     header[20] || counter[4] || final_flag[1]
 *
 *   final_flag    0x00 on data records, 0x01 on the terminator record.
 *
 *   TERMINATOR: the last record of every stream is a mandatory record whose
 *   plaintext is empty, so its ct_len is exactly 16 (the bare GCM tag) and its
 *   AAD final_flag is 0x01. A reader distinguishes record kinds by length alone:
 *
 *       ct_len == 16  -> terminator (empty plaintext, final_flag 0x01)
 *       ct_len  > 16  -> data record (plaintext length ct_len - 16, flag 0x00)
 *
 *   This is unambiguous because a data record is only ever written for a
 *   non-empty read, so no data record can have ct_len == 16.
 *
 *   A zero-byte input therefore encodes as header + terminator and nothing else
 *   (20 + 32 = 52 bytes).
 *
 * WHY THE TERMINATOR EXISTS: without an authenticated end-of-stream marker, an
 * attacker could lop whole records off the end and every surviving record would
 * still authenticate perfectly - classic stream truncation. Readers MUST treat
 * "EOF before a terminator" and "any bytes after a terminator" as hard errors.
 *
 * WHAT THE AAD BUYS: binding the counter stops records being reordered or
 * dropped from the middle; binding the header pins the format version,
 * chunk_size and nonce_prefix so neither a header rewrite nor a record spliced
 * in from a different stream under the same key can be reinterpreted; binding
 * final_flag stops a data record being passed off as a terminator.
 *
 * WHY AN 8-BYTE PREFIX (deviation from the phase plan, which specified 4):
 * one archive key encrypts MANY streams - data.enc plus every attachment - so
 * prefix collisions across streams under one key are the binding constraint.
 * A shared prefix means two streams reuse the same (key, nonce) pair, and GCM
 * nonce reuse is catastrophic: it leaks the plaintext XOR and lets an attacker
 * forge tags. With a 32-bit prefix the birthday bound gives ~0.3% collision
 * odds at 5,000 attachments and ~4.5% at 20,000 - far too likely for a job
 * that runs unattended for years. 64 bits drops that below 1e-12 at the same
 * scale. Putting the prefix in the AAD-bound header (rather than inferring it
 * from record 0) additionally makes a cross-stream splice fail on record 0
 * instead of decrypting one wrong chunk before failing on record 1.
 *
 * =============================================================================
 *
 * Failure policy: every corruption throws \RuntimeException. This class NEVER
 * returns plaintext it could not authenticate, and never falls back to
 * returning the input on failure.
 *
 * This class never touches the vault. It takes a raw key argument and nothing
 * else, so it is safe on the cron path where the vault is locked.
 *
 * @see Fotonic_Backup_Keys for how the archive key (AK) is sealed.
 */
class Fotonic_Backup_Cipher {

    const MAGIC              = 'FTNCBK01';
    const HEADER_LENGTH      = 20;
    const PREFIX_LENGTH      = 8;
    const MAX_COUNTER        = 4294967295; // 2^32 - 1, the uint32 counter ceiling.
    const DEFAULT_CHUNK_SIZE = 1048576; // 1 MiB
    const MAX_CHUNK_SIZE     = 67108864; // 64 MiB — sanity bound, not a format limit.
    const TAG_LENGTH         = 16;
    const NONCE_LENGTH       = 12;
    const CIPHER             = 'aes-256-gcm';

    const FLAG_DATA       = "\x00";
    const FLAG_TERMINATOR = "\x01";

    /**
     * Whether the server can perform AES-256-GCM.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        return in_array( self::CIPHER, openssl_get_cipher_methods(), true );
    }

    /**
     * Encrypt everything readable from $in into $out.
     *
     * @param resource $in         Readable stream.
     * @param resource $out        Writable stream.
     * @param string   $key        Raw 32-byte key.
     * @param int      $chunk_size Plaintext bytes per record.
     * @return int Plaintext bytes consumed.
     * @throws \RuntimeException On bad arguments or any encryption failure.
     */
    public static function encrypt_stream( $in, $out, string $key, int $chunk_size = self::DEFAULT_CHUNK_SIZE ): int {
        self::assert_key( $key );
        self::assert_stream( $in, 'input' );
        self::assert_stream( $out, 'output' );

        if ( ! self::is_supported() ) {
            throw new \RuntimeException( 'AES-256-GCM is not available on this server.' );
        }
        if ( $chunk_size < 1 || $chunk_size > self::MAX_CHUNK_SIZE ) {
            throw new \RuntimeException( 'Invalid chunk size: ' . $chunk_size );
        }

        $prefix = random_bytes( self::PREFIX_LENGTH );
        $header = self::MAGIC . pack( 'N', $chunk_size ) . $prefix;
        self::write_all( $out, $header );

        $counter = 0;
        $total   = 0;

        while ( true ) {
            $plain = self::read_up_to( $in, $chunk_size );
            if ( '' === $plain ) {
                break;
            }
            if ( $counter >= self::MAX_COUNTER ) {
                // Leave room for the terminator; a stream this large cannot be
                // represented without reusing a nonce.
                throw new \RuntimeException( 'Input exceeds the maximum number of records for one stream.' );
            }
            $total += strlen( $plain );
            self::write_record( $out, $header, $prefix, $counter, $plain, self::FLAG_DATA, $key );
            $counter++;
        }

        // Mandatory terminator: empty plaintext, final flag set.
        $empty = '';
        self::write_record( $out, $header, $prefix, $counter, $empty, self::FLAG_TERMINATOR, $key );

        return $total;
    }

    /**
     * Decrypt a stream produced by encrypt_stream().
     *
     * @param resource $in  Readable stream positioned at the header.
     * @param resource $out Writable stream.
     * @param string   $key Raw 32-byte key.
     * @return int Plaintext bytes written.
     * @throws \RuntimeException On any corruption, tampering or truncation.
     */
    public static function decrypt_stream( $in, $out, string $key ): int {
        self::assert_key( $key );
        self::assert_stream( $in, 'input' );
        self::assert_stream( $out, 'output' );

        if ( ! self::is_supported() ) {
            throw new \RuntimeException( 'AES-256-GCM is not available on this server.' );
        }

        $header = self::read_up_to( $in, self::HEADER_LENGTH );
        if ( strlen( $header ) !== self::HEADER_LENGTH ) {
            throw new \RuntimeException( 'Truncated archive: incomplete header.' );
        }
        if ( 0 !== strncmp( $header, self::MAGIC, 8 ) ) {
            throw new \RuntimeException( 'Not a Fotonic backup stream, or unsupported format version.' );
        }

        // Authoritative prefix comes from the header, which every record's AAD
        // binds — so a record spliced in from another stream under the same key
        // fails immediately rather than yielding one wrong chunk first.
        $prefix           = substr( $header, 12, self::PREFIX_LENGTH );
        $expected_counter = 0;
        $saw_terminator   = false;
        $total            = 0;

        while ( true ) {
            $length_field = self::read_up_to( $in, 4 );
            if ( '' === $length_field ) {
                break; // Clean EOF.
            }
            if ( 4 !== strlen( $length_field ) ) {
                throw new \RuntimeException( 'Truncated archive: incomplete record length.' );
            }
            if ( $saw_terminator ) {
                throw new \RuntimeException( 'Malformed archive: data found after the terminator record.' );
            }

            $unpacked = unpack( 'N', $length_field );
            $ct_len   = (int) $unpacked[1];
            if ( $ct_len < self::TAG_LENGTH ) {
                throw new \RuntimeException( 'Malformed archive: record shorter than the authentication tag.' );
            }

            $nonce = self::read_up_to( $in, self::NONCE_LENGTH );
            if ( self::NONCE_LENGTH !== strlen( $nonce ) ) {
                throw new \RuntimeException( 'Truncated archive: incomplete nonce.' );
            }

            // Explicit positional check against the header-derived prefix. The
            // AAD binding below would catch a reordered record anyway, but
            // failing here gives a precise error.
            if ( $nonce !== $prefix . pack( 'N', $expected_counter ) ) {
                throw new \RuntimeException( 'Malformed archive: record ' . $expected_counter . ' is out of order, from another stream, or its nonce was altered.' );
            }

            // Read ciphertext and tag separately: slicing one combined buffer
            // would cost an extra full-chunk copy and push peak memory to ~3x.
            $ciphertext = self::read_up_to( $in, $ct_len - self::TAG_LENGTH );
            if ( strlen( $ciphertext ) !== $ct_len - self::TAG_LENGTH ) {
                throw new \RuntimeException( 'Truncated archive: incomplete record body.' );
            }
            $tag = self::read_up_to( $in, self::TAG_LENGTH );
            if ( self::TAG_LENGTH !== strlen( $tag ) ) {
                throw new \RuntimeException( 'Truncated archive: incomplete authentication tag.' );
            }

            $is_terminator = ( self::TAG_LENGTH === $ct_len );
            $flag          = $is_terminator ? self::FLAG_TERMINATOR : self::FLAG_DATA;
            $aad           = $header . pack( 'N', $expected_counter ) . $flag;

            $plain      = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
            $ciphertext = ''; // Release before the plaintext chunk is written.

            if ( false === $plain ) {
                throw new \RuntimeException( 'Authentication failed on record ' . $expected_counter . ': the archive is corrupt or was tampered with.' );
            }

            if ( $is_terminator ) {
                $saw_terminator = true;
            } else {
                $total += strlen( $plain );
                self::write_all( $out, $plain );
            }

            $plain = '';
            $expected_counter++;
        }

        if ( ! $saw_terminator ) {
            throw new \RuntimeException( 'Truncated archive: the terminator record is missing.' );
        }

        return $total;
    }

    /**
     * Convenience wrapper: encrypt a string, same wire format.
     *
     * @param string $plaintext  Data to encrypt.
     * @param string $key        Raw 32-byte key.
     * @param int    $chunk_size Plaintext bytes per record.
     * @return string Encrypted blob.
     * @throws \RuntimeException On failure.
     */
    public static function encrypt_string( string $plaintext, string $key, int $chunk_size = self::DEFAULT_CHUNK_SIZE ): string {
        $in  = self::temp_stream( $plaintext );
        $out = self::temp_stream( '' );

        try {
            self::encrypt_stream( $in, $out, $key, $chunk_size );
            rewind( $out );
            $result = stream_get_contents( $out );
            if ( false === $result ) {
                throw new \RuntimeException( 'Could not read back the encrypted stream.' );
            }
            return $result;
        } finally {
            fclose( $in );
            fclose( $out );
        }
    }

    /**
     * Convenience wrapper: decrypt a blob produced by encrypt_string().
     *
     * @param string $blob Encrypted blob.
     * @param string $key  Raw 32-byte key.
     * @return string Plaintext.
     * @throws \RuntimeException On any corruption.
     */
    public static function decrypt_string( string $blob, string $key ): string {
        $in  = self::temp_stream( $blob );
        $out = self::temp_stream( '' );

        try {
            self::decrypt_stream( $in, $out, $key );
            rewind( $out );
            $result = stream_get_contents( $out );
            if ( false === $result ) {
                throw new \RuntimeException( 'Could not read back the decrypted stream.' );
            }
            return $result;
        } finally {
            fclose( $in );
            fclose( $out );
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Encrypt one record and write it straight to $out.
     *
     * Writes the length+nonce, ciphertext and tag as three separate writes
     * rather than concatenating them into one buffer. Concatenating would cost
     * two extra full-chunk copies and push peak memory to ~4x the chunk size;
     * this keeps it at ~2x, which is the floor for GCM (input and output
     * buffers must both exist while openssl_encrypt runs).
     *
     * $plain is taken BY REFERENCE and freed here, as soon as openssl_encrypt
     * has consumed it, so the plaintext and ciphertext buffers do not stay
     * alive together any longer than necessary.
     *
     * @param resource $out     Writable stream.
     * @param string   $header  The 20-byte stream header (bound into the AAD).
     * @param string   $prefix  8-byte per-stream nonce prefix.
     * @param int      $counter Record index.
     * @param string   $plain   Plaintext ('' for the terminator); emptied in place.
     * @param string   $flag    FLAG_DATA or FLAG_TERMINATOR.
     * @param string   $key     Raw 32-byte key.
     * @return void
     * @throws \RuntimeException On encryption failure.
     */
    private static function write_record( $out, string $header, string $prefix, int $counter, string &$plain, string $flag, string $key ): void {
        $nonce = $prefix . pack( 'N', $counter );
        $aad   = $header . pack( 'N', $counter ) . $flag;
        $tag   = '';

        $ciphertext = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_LENGTH );
        $plain      = ''; // Release the plaintext chunk immediately.

        if ( false === $ciphertext ) {
            throw new \RuntimeException( 'Encryption failed on record ' . $counter . '.' );
        }
        if ( self::TAG_LENGTH !== strlen( $tag ) ) {
            throw new \RuntimeException( 'Unexpected GCM tag length on record ' . $counter . '.' );
        }

        self::write_all( $out, pack( 'N', strlen( $ciphertext ) + self::TAG_LENGTH ) . $nonce );
        self::write_all( $out, $ciphertext );
        self::write_all( $out, $tag );
    }

    /**
     * Read up to $length bytes, looping over short reads. Returns fewer bytes
     * only at end of stream.
     *
     * @param resource $handle Stream to read.
     * @param int      $length Maximum bytes wanted.
     * @return string
     */
    private static function read_up_to( $handle, int $length ): string {
        if ( $length <= 0 ) {
            return '';
        }

        $buffer = fread( $handle, $length );
        if ( false === $buffer ) {
            return '';
        }
        // Fast path: regular files satisfy a read in one call, so the common
        // case returns without ever holding two copies of the chunk.
        if ( strlen( $buffer ) >= $length ) {
            return $buffer;
        }

        while ( strlen( $buffer ) < $length ) {
            $piece = fread( $handle, $length - strlen( $buffer ) );
            if ( false === $piece || '' === $piece ) {
                break;
            }
            $buffer .= $piece;
        }
        return $buffer;
    }

    /**
     * Write a full buffer, looping over partial writes.
     *
     * @param resource $handle Stream to write.
     * @param string   $data   Bytes to write.
     * @return void
     * @throws \RuntimeException If the stream stops accepting data.
     */
    private static function write_all( $handle, string $data ): void {
        $length = strlen( $data );
        if ( 0 === $length ) {
            return;
        }

        // Fast path: avoid the substr() copy that the resume loop below needs.
        $written = fwrite( $handle, $data );
        if ( false === $written || 0 === $written ) {
            throw new \RuntimeException( 'Write failed after 0 of ' . $length . ' bytes.' );
        }

        while ( $written < $length ) {
            $result = fwrite( $handle, substr( $data, $written ) );
            if ( false === $result || 0 === $result ) {
                throw new \RuntimeException( 'Write failed after ' . $written . ' of ' . $length . ' bytes.' );
            }
            $written += $result;
        }
    }

    /**
     * A php://temp stream seeded with $contents and rewound.
     *
     * php://temp spills to disk past ~2 MB, so the string helpers stay bounded
     * too.
     *
     * @param string $contents Initial contents.
     * @return resource
     * @throws \RuntimeException If the stream cannot be opened.
     */
    private static function temp_stream( string $contents ) {
        $handle = fopen( 'php://temp', 'w+b' );
        if ( false === $handle ) {
            throw new \RuntimeException( 'Could not open a temporary stream.' );
        }
        if ( '' !== $contents ) {
            self::write_all( $handle, $contents );
        }
        rewind( $handle );
        return $handle;
    }

    /**
     * @param string $key Key to validate.
     * @return void
     * @throws \RuntimeException If the key is not exactly 32 bytes.
     */
    private static function assert_key( string $key ): void {
        if ( 32 !== strlen( $key ) ) {
            throw new \RuntimeException( 'A raw 32-byte key is required; got ' . strlen( $key ) . ' bytes.' );
        }
    }

    /**
     * @param mixed  $handle Value that should be a stream.
     * @param string $label  Name used in the error message.
     * @return void
     * @throws \RuntimeException If it is not a stream resource.
     */
    private static function assert_stream( $handle, string $label ): void {
        if ( ! is_resource( $handle ) ) {
            throw new \RuntimeException( 'Invalid ' . $label . ' stream.' );
        }
    }
}
