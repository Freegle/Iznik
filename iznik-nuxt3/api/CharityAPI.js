import BaseAPI from '@/api/BaseAPI'

export default class CharityAPI extends BaseAPI {
  signup(data) {
    return this.$postv2('/charities', data)
  }
}
