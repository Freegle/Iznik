import BaseAPI from '@/api/BaseAPI'

export default class GiftAidAPI extends BaseAPI {
  async get() {
    try {
      // logError=false: v2 API returns 404 when user has no declaration
      return await this.$getv2('/giftaid', {}, false)
    } catch (e) {
      return null
    }
  }

  async list() {
    const ret = await this.$getv2('/giftaid', {
      all: true,
    })

    return ret.giftaids
  }

  async search(val) {
    const ret = await this.$getv2('/giftaid', {
      search: val,
    })

    return ret.giftaids
  }

  // Donors we have asked for a declaration who have not answered.
  async noResponse() {
    const ret = await this.$getv2('/giftaid', {
      noresponse: true,
    })

    return ret.donors
  }

  // A Gift Aid volunteer records a donor's answer for them: outcome is
  // 'Declined' (they told us we cannot claim) or 'NoResponse' (we asked again
  // and heard nothing).
  // A 404 (no such user) or 409 (the donor has already answered) is shown to
  // the volunteer, not reported.
  record(userid, outcome) {
    return this.$postv2(
      '/giftaid/record',
      { userid, outcome },
      (data) => data?.error !== 404 && data?.error !== 409
    )
  }

  save({ period, fullname, firstname, lastname, homeaddress }) {
    return this.$postv2('/giftaid', {
      period,
      fullname,
      firstname,
      lastname,
      homeaddress,
    })
  }

  edit(
    id,
    period,
    fullname,
    homeaddress,
    postcode,
    housenameornumber,
    reviewed,
    deleted,
    firstname,
    lastname
  ) {
    const data = {
      id,
      period,
      fullname,
      firstname,
      lastname,
      homeaddress,
      postcode,
      housenameornumber,
      reviewed,
      deleted,
    }
    return this.$patchv2('/giftaid', data)
  }

  remove() {
    return this.$delv2('/giftaid')
  }
}
