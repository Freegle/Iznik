<template>
  <div>
    <b-button
      :variant="variant"
      :size="size"
      :class="btnClass"
      @click="download"
    >
      <v-icon icon="calendar-alt" />
      Add to Calendar
    </b-button>
    <div v-if="message" class="mt-1 small text-muted">{{ message }}</div>
  </div>
</template>
<script setup>
import saveAs from 'save-file'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { buildIcs } from '~/composables/useCalendarEvent'
import { useMobileStore } from '@/stores/mobile' // APP

dayjs.extend(utc)
dayjs.extend(timezone)

const props = defineProps({
  variant: {
    type: String,
    required: false,
    default: 'secondary',
  },
  calendarLink: {
    type: String,
    required: true,
  },
  size: {
    type: String,
    required: false,
    default: 'md',
  },
  btnClass: {
    type: String,
    required: false,
    default: '',
  },
})

// Shown to the member when we fall back to a downloaded file or something goes wrong, so the
// button never silently does nothing (Discourse 9927).
const message = ref(null)

// Pull the base64 event payload out of the calendar link.
function parseEventData(link) {
  const encodedData = new URL(link).searchParams.get('data')
  if (!encodedData) {
    return null
  }
  return JSON.parse(atob(encodedData))
}

// Download an .ics that any calendar app can import. Used for the web, and as the app fallback
// when the native calendar hook is unavailable or errors - so the button always does something.
async function downloadIcs(eventData) {
  const blob = new Blob([buildIcs(eventData)], {
    type: 'text/calendar;charset=utf-8',
  })
  await saveAs(blob, 'freegle-handover.ics')
}

// Parse the event's start/end into Date objects in its own timezone, falling back to local time.
function eventDates(eventData) {
  try {
    const start = dayjs
      .tz(`${eventData.startDate} ${eventData.startTime}`, eventData.timeZone)
      .toDate()
    const end = dayjs
      .tz(`${eventData.startDate} ${eventData.endTime}`, eventData.timeZone)
      .toDate()
    if (isNaN(start.getTime()) || isNaN(end.getTime())) {
      throw new TypeError('Invalid dates from timezone parsing')
    }
    return { start, end }
  } catch (err) {
    const [year, month, day] = eventData.startDate.split('-').map(Number)
    const [sh, sm] = eventData.startTime.split(':').map(Number)
    const [eh, em] = eventData.endTime.split(':').map(Number)
    return {
      start: new Date(year, month - 1, day, sh, sm, 0),
      end: new Date(year, month - 1, day, eh, em, 0),
    }
  }
}

async function download(e) {
  e.preventDefault()
  e.stopPropagation()
  message.value = null

  let eventData
  try {
    eventData = parseEventData(props.calendarLink)
  } catch (err) {
    eventData = null
  }
  if (!eventData) {
    console.error(
      'AddToCalendar: could not read the calendar link',
      props.calendarLink
    )
    message.value = 'Sorry, we could not read the event details.'
    return
  }

  const mobileStore = useMobileStore()

  // Web: hand the browser an .ics to import.
  if (!mobileStore.isApp) {
    try {
      await downloadIcs(eventData)
    } catch (err) {
      console.error('AddToCalendar: failed to build/save the .ics', err)
      message.value = "Sorry, we couldn't create the calendar file."
    }
    return
  }

  // App: try the native calendar plugin. If it is missing (it may not be exposed in the
  // WebView) or it errors, fall back to the .ics download so the button is never a silent
  // no-op - the symptom reported in 9927.
  const fallback = async () => {
    try {
      await downloadIcs(eventData)
      message.value =
        "We've saved a calendar file - open it to add this to your calendar."
    } catch (err) {
      console.error('AddToCalendar: fallback .ics failed', err)
      message.value = "Sorry, we couldn't add this to your calendar."
    }
  }

  const cal = window.plugins?.calendar
  if (!cal) {
    console.warn(
      'AddToCalendar: native calendar plugin unavailable; using .ics fallback'
    )
    await fallback()
    return
  }

  const { start, end } = eventDates(eventData)
  const title = eventData.name
  const eventLocation = eventData.location || ''
  const notes = eventData.description

  const onError = async (msg) => {
    console.error('AddToCalendar: native calendar error', msg)
    await fallback()
  }
  const onSuccess = () => {
    message.value = null
  }
  const createEvent = () => {
    try {
      cal.createEventInteractively(
        title,
        eventLocation,
        notes,
        start,
        end,
        onSuccess,
        onError
      )
    } catch (err) {
      onError(err)
    }
  }

  try {
    cal.hasWritePermission((has) => {
      if (has || !mobileStore.isiOS) {
        createEvent()
      } else {
        cal.requestWritePermission(createEvent, onError)
      }
    }, onError)
  } catch (err) {
    onError(err)
  }
}
</script>
