import { getTestState } from '../../../utils/testState'

export default defineEventHandler(async () => {
  const state = getTestState('laravel')
  return {
    status: state.status,
    message: state.message,
    logs: state.logs,
    progress: state.progress,
    startTime: state.startTime,
    endTime: state.endTime,
    success: state.success,
  }
})
