/* `.ftnc-section-heading` owns the rule and the gap below it (DESIGN.md §4).
   Never re-add `border-b border-gray-200 pb-2 mb-4` here or at a call site —
   the class and the utilities would be two sources of truth for one property.
   The gap lives on this element's own margin-bottom, so a Pro slot rendered as
   the section body inherits the rhythm without carrying any class of its own. */
export default function SectionHeading({ children, description, action }) {
  return (
    <div className="ftnc-section-heading">
      <div className="flex flex-col gap-1 min-w-0">
        <h2 className="text-base font-semibold text-gray-800">{children}</h2>
        {description && <p className="text-sm text-gray-500">{description}</p>}
      </div>
      {action && <div className="ml-auto shrink-0">{action}</div>}
    </div>
  )
}
