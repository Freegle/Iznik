import BaseAPI from '@/api/BaseAPI'

// Support cases: a chat a moderator has referred to Support, with the first pass and the
// replies between the referrer and Support.
export default class SupportCasesAPI extends BaseAPI {
  // params: { status: 'Open' | 'Handled' | 'All', before, limit } or { chatid }.
  fetchCases(params) {
    return this.$getv2('/supportcases', params)
  }

  fetchCase(id) {
    return this.$getv2(`/supportcases/${id}`)
  }

  // draftused is 'No', 'Unchanged' or 'Edited'.
  sendMessage(id, body, draftused) {
    const data = { body }
    if (draftused) data.draftused = draftused
    return this.$postv2(`/supportcases/${id}/messages`, data)
  }

  setStatus(id, status) {
    return this.$patchv2('/supportcases', { id, status })
  }
}
