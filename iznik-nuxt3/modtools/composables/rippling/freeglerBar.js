// Builds the HTML for the "~X would be notified" sidebar bar.
// Returns null when the bar should be hidden (zero estimate or no located total).
export function buildFreeglerBarHTML(
  estimatedInsideLocated,
  totalLocated,
  unlocatedFraction
) {
  if (!(estimatedInsideLocated > 0 && totalLocated > 0)) return null
  const totalEstimate = Math.round(
    estimatedInsideLocated / (1 - unlocatedFraction)
  )
  const unlocatedShare = totalEstimate - estimatedInsideLocated
  return (
    `<div style="font-size:13px;font-weight:600;color:#333;line-height:1.4">~${totalEstimate.toLocaleString()} would be notified</div>` +
    `<div style="font-size:10px;color:#666;margin-top:1px">${estimatedInsideLocated.toLocaleString()} with known location` +
    (unlocatedShare > 0
      ? ` + ~${unlocatedShare.toLocaleString()} estimated unlocated`
      : '') +
    `</div>`
  )
}
