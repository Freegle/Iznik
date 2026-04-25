import { ref } from 'vue'

export function useSwipeGesture(options = {}) {
  const { threshold = 80, onSwipeLeft = null, onSwipeRight = null } = options

  const offsetX = ref(0)
  const swiping = ref(false)

  let startX = 0
  let startY = 0
  let tracking = false

  function onTouchStart(e) {
    const touch = e.touches[0]
    startX = touch.clientX
    startY = touch.clientY
    tracking = true
    swiping.value = false
    offsetX.value = 0
  }

  function onTouchMove(e) {
    if (!tracking) return

    const touch = e.touches[0]
    const dx = touch.clientX - startX
    const dy = touch.clientY - startY

    // If vertical movement dominates, stop tracking horizontal swipe
    if (!swiping.value && Math.abs(dy) > Math.abs(dx)) {
      tracking = false
      offsetX.value = 0
      return
    }

    swiping.value = true
    offsetX.value = dx
  }

  function onTouchEnd() {
    if (!tracking) return

    tracking = false

    if (offsetX.value > threshold && onSwipeRight) {
      onSwipeRight()
    } else if (offsetX.value < -threshold && onSwipeLeft) {
      onSwipeLeft()
    }

    swiping.value = false
    offsetX.value = 0
  }

  return {
    offsetX,
    swiping,
    onTouchStart,
    onTouchMove,
    onTouchEnd,
  }
}
