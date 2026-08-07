import { useEffect, useRef, useState, useCallback } from 'react'
import { Search, ChevronUp, ChevronDown, ZoomIn, ZoomOut } from 'lucide-react'
import { __ } from '../utils/i18n'
import Spinner from './Spinner'

const MIN_SCALE = 0.5
const MAX_SCALE = 3
const SCALE_STEP = 0.25
const DEFAULT_SCALE = 1.25

// Renders a PDF (given as an ArrayBuffer) as a continuous, virtualized scroll of pages,
// each with a real pdf.js text layer (selectable + searchable), using only pdf.js's
// rendering primitives — not the larger pdfjs-dist/web/pdf_viewer UI framework.
export default function PdfViewer({ data }) {
  const [status, setStatus] = useState('loading') // loading | ready | error
  const [numPages, setNumPages] = useState(0)
  const [pageViewports, setPageViewports] = useState([])
  const [scale, setScale] = useState(DEFAULT_SCALE)
  const [currentPage, setCurrentPage] = useState(1)
  const [pageInput, setPageInput] = useState('1')
  const [query, setQuery] = useState('')
  const [matches, setMatches] = useState([])
  const [matchIndex, setMatchIndex] = useState(-1)
  const [, setTextIndexTick] = useState(0)

  const pdfRef = useRef(null)
  const pdfjsLibRef = useRef(null)
  const scrollRef = useRef(null)
  const observerRef = useRef(null)
  const pageEls = useRef(new Map())
  const canvasEls = useRef(new Map())
  const textLayerEls = useRef(new Map())
  const textLayerObjs = useRef(new Map())
  const pageTextContentRef = useRef(new Map())
  const pageTextRef = useRef(new Map())
  const renderedPages = useRef(new Set())
  const renderingPages = useRef(new Set())
  const ratiosRef = useRef(new Map())
  const scaleRef = useRef(DEFAULT_SCALE)

  useEffect(() => {
    scaleRef.current = scale
  }, [scale])

  // Load the document (lazy pdf.js import keeps it out of the main bundle).
  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const pdfjsLib = await import('pdfjs-dist/build/pdf.mjs')
        pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
          'pdfjs-dist/build/pdf.worker.min.mjs',
          import.meta.url
        ).href
        pdfjsLibRef.current = pdfjsLib

        const pdf = await pdfjsLib.getDocument({ data }).promise
        if (cancelled) return
        pdfRef.current = pdf

        const viewports = new Array(pdf.numPages + 1)
        for (let n = 1; n <= pdf.numPages; n++) {
          const page = await pdf.getPage(n)
          viewports[n] = page.getViewport({ scale: 1 })
        }
        if (cancelled) return
        setPageViewports(viewports)
        setNumPages(pdf.numPages)
        setStatus('ready')
      } catch {
        if (!cancelled) setStatus('error')
      }
    })()
    return () => {
      cancelled = true
    }
  }, [data])

  // Eagerly fetch text content (cheap, no canvas) for every page so full-document
  // search works without waiting for lazy visual rendering.
  useEffect(() => {
    if (status !== 'ready') return
    let cancelled = false
    ;(async () => {
      const pdf = pdfRef.current
      for (let n = 1; n <= pdf.numPages; n++) {
        if (cancelled) return
        if (!pageTextContentRef.current.has(n)) {
          const page = await pdf.getPage(n)
          const textContent = await page.getTextContent()
          pageTextContentRef.current.set(n, textContent)
          pageTextRef.current.set(
            n,
            textContent.items.map((item) => item.str).join(' ').toLowerCase()
          )
        }
      }
      if (!cancelled) setTextIndexTick((t) => t + 1)
    })()
    return () => {
      cancelled = true
    }
  }, [status])

  // Free pdf.js resources (worker, memory) when the viewer unmounts.
  useEffect(() => {
    return () => {
      observerRef.current?.disconnect()
      pdfRef.current?.destroy?.()
    }
  }, [])

  const renderPage = useCallback(async (pageNum) => {
    if (renderedPages.current.has(pageNum) || renderingPages.current.has(pageNum)) return
    const pdf = pdfRef.current
    const pdfjsLib = pdfjsLibRef.current
    const canvas = canvasEls.current.get(pageNum)
    const textLayerDiv = textLayerEls.current.get(pageNum)
    if (!pdf || !pdfjsLib || !canvas || !textLayerDiv) return

    renderingPages.current.add(pageNum)
    try {
      const page = await pdf.getPage(pageNum)
      const viewport = page.getViewport({ scale: scaleRef.current })

      const outputScale = window.devicePixelRatio || 1
      canvas.width = Math.floor(viewport.width * outputScale)
      canvas.height = Math.floor(viewport.height * outputScale)
      canvas.style.width = `${viewport.width}px`
      canvas.style.height = `${viewport.height}px`
      const ctx = canvas.getContext('2d')
      const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null
      await page.render({ canvasContext: ctx, viewport, transform }).promise

      let textContent = pageTextContentRef.current.get(pageNum)
      if (!textContent) {
        textContent = await page.getTextContent()
        pageTextContentRef.current.set(pageNum, textContent)
        pageTextRef.current.set(
          pageNum,
          textContent.items.map((item) => item.str).join(' ').toLowerCase()
        )
      }
      textLayerDiv.replaceChildren()
      const textLayer = new pdfjsLib.TextLayer({
        textContentSource: textContent,
        container: textLayerDiv,
        viewport,
      })
      await textLayer.render()
      textLayerObjs.current.set(pageNum, textLayer)

      renderedPages.current.add(pageNum)
    } finally {
      renderingPages.current.delete(pageNum)
    }
  }, [])

  // Virtualization: only render pages once they scroll near the viewport.
  useEffect(() => {
    if (status !== 'ready') return
    const observer = new IntersectionObserver(
      (entries) => {
        let bestPage = null
        let bestRatio = 0
        entries.forEach((entry) => {
          const pageNum = Number(entry.target.dataset.page)
          ratiosRef.current.set(pageNum, entry.isIntersecting ? entry.intersectionRatio : 0)
          if (entry.isIntersecting) renderPage(pageNum)
        })
        ratiosRef.current.forEach((ratio, pageNum) => {
          if (ratio > bestRatio) {
            bestRatio = ratio
            bestPage = pageNum
          }
        })
        if (bestPage) {
          setCurrentPage(bestPage)
          setPageInput(String(bestPage))
        }
      },
      { root: scrollRef.current, rootMargin: '100% 0px 100% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] }
    )
    observerRef.current = observer
    pageEls.current.forEach((el) => observer.observe(el))
    return () => observer.disconnect()
  }, [status, renderPage])

  // Re-render already-rendered pages at the new zoom level.
  useEffect(() => {
    if (status !== 'ready') return
    const pages = [...renderedPages.current]
    pages.forEach((n) => {
      renderedPages.current.delete(n)
      renderPage(n)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scale])

  async function goToPage(n) {
    if (!numPages || n < 1 || n > numPages) return
    if (!renderedPages.current.has(n)) {
      await renderPage(n)
    }
    pageEls.current.get(n)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    setCurrentPage(n)
    setPageInput(String(n))
  }

  function handlePageInputCommit() {
    const n = parseInt(pageInput, 10)
    if (!Number.isNaN(n) && n >= 1 && n <= numPages) {
      goToPage(n)
    } else {
      setPageInput(String(currentPage))
    }
  }

  function clearHighlights() {
    textLayerEls.current.forEach((el) => {
      el.querySelectorAll('.ftnc-pdf-highlight').forEach((span) => span.classList.remove('ftnc-pdf-highlight'))
    })
  }

  async function jumpToMatch(pageNum, lowerQuery) {
    await goToPage(pageNum)
    clearHighlights()
    const textLayer = textLayerObjs.current.get(pageNum)
    if (!textLayer) return
    const { textDivs, textContentItemsStr } = textLayer
    for (let i = 0; i < textContentItemsStr.length; i++) {
      if (textContentItemsStr[i].toLowerCase().includes(lowerQuery)) {
        textDivs[i].classList.add('ftnc-pdf-highlight')
        textDivs[i].scrollIntoView({ block: 'center', behavior: 'smooth' })
        break
      }
    }
  }

  // Debounced full-document search over the eagerly-fetched per-page text index.
  useEffect(() => {
    const q = query.trim().toLowerCase()
    const timer = setTimeout(() => {
      if (!q) {
        setMatches([])
        setMatchIndex(-1)
        clearHighlights()
        return
      }
      const found = []
      for (let n = 1; n <= numPages; n++) {
        if ((pageTextRef.current.get(n) ?? '').includes(q)) found.push(n)
      }
      setMatches(found)
      setMatchIndex(found.length ? 0 : -1)
      if (found.length) jumpToMatch(found[0], q)
    }, 200)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, numPages])

  function nextMatch() {
    if (!matches.length) return
    const next = (matchIndex + 1) % matches.length
    setMatchIndex(next)
    jumpToMatch(matches[next], query.trim().toLowerCase())
  }

  function prevMatch() {
    if (!matches.length) return
    const prev = (matchIndex - 1 + matches.length) % matches.length
    setMatchIndex(prev)
    jumpToMatch(matches[prev], query.trim().toLowerCase())
  }

  function zoomIn() {
    setScale((s) => Math.min(MAX_SCALE, +(s + SCALE_STEP).toFixed(2)))
  }

  function zoomOut() {
    setScale((s) => Math.max(MIN_SCALE, +(s - SCALE_STEP).toFixed(2)))
  }

  if (status === 'error') {
    return (
      <div className="flex items-center justify-center h-full text-sm text-red-600">
        {__('Unable to render this PDF.', 'eleva-crm-for-photographers')}
      </div>
    )
  }

  const pages = Array.from({ length: numPages }, (_, i) => i + 1)

  return (
    <div className="flex flex-col h-full">
      <div className="flex items-center gap-2 pb-3 border-b border-gray-200 shrink-0 flex-wrap">
        <div className="relative flex-1 min-w-[160px]">
          <Search size={14} className="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={__('Search in document…', 'eleva-crm-for-photographers')}
            className="w-full pl-7 pr-2 py-1 text-sm border border-gray-300 rounded"
          />
        </div>
        {matches.length > 0 && (
          <span className="text-xs text-gray-500 whitespace-nowrap">{matchIndex + 1}/{matches.length}</span>
        )}
        <button
          type="button"
          onClick={prevMatch}
          disabled={!matches.length}
          className="p-1 text-gray-500 hover:text-gray-700 disabled:opacity-30"
          aria-label={__('Previous match', 'eleva-crm-for-photographers')}
        >
          <ChevronUp size={16} />
        </button>
        <button
          type="button"
          onClick={nextMatch}
          disabled={!matches.length}
          className="p-1 text-gray-500 hover:text-gray-700 disabled:opacity-30"
          aria-label={__('Next match', 'eleva-crm-for-photographers')}
        >
          <ChevronDown size={16} />
        </button>

        <div className="w-px h-5 bg-gray-200 mx-1" />

        <button type="button" onClick={zoomOut} className="p-1 text-gray-500 hover:text-gray-700" aria-label={__('Zoom out', 'eleva-crm-for-photographers')}>
          <ZoomOut size={16} />
        </button>
        <button type="button" onClick={zoomIn} className="p-1 text-gray-500 hover:text-gray-700" aria-label={__('Zoom in', 'eleva-crm-for-photographers')}>
          <ZoomIn size={16} />
        </button>

        <div className="w-px h-5 bg-gray-200 mx-1" />

        <div className="flex items-center gap-1 text-xs text-gray-500 whitespace-nowrap">
          <input
            type="text"
            inputMode="numeric"
            value={pageInput}
            onChange={(e) => setPageInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            onBlur={handlePageInputCommit}
            className="w-10 text-center border border-gray-300 rounded py-0.5"
            aria-label={__('Page number', 'eleva-crm-for-photographers')}
          />
          <span>/ {numPages || '…'}</span>
        </div>
      </div>

      <div ref={scrollRef} className="flex-1 min-h-0 overflow-auto bg-gray-100 pt-4">
        {status === 'loading' ? (
          <div className="flex items-center justify-center h-full">
            <Spinner />
          </div>
        ) : (
          <div className="flex flex-col items-center gap-4 pb-4">
            {pages.map((n) => {
              const base = pageViewports[n]
              const width = base ? base.width * scale : 600
              const height = base ? base.height * scale : 800
              return (
                <div
                  key={n}
                  data-page={n}
                  ref={(el) => {
                    if (el) pageEls.current.set(n, el)
                    else pageEls.current.delete(n)
                  }}
                  className="ftnc-pdf-page"
                  style={{ width, height }}
                >
                  <canvas
                    ref={(el) => {
                      if (el) canvasEls.current.set(n, el)
                      else canvasEls.current.delete(n)
                    }}
                  />
                  <div
                    ref={(el) => {
                      if (el) textLayerEls.current.set(n, el)
                      else textLayerEls.current.delete(n)
                    }}
                    className="ftnc-pdf-textlayer"
                  />
                </div>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}
