import BaseAPI from '@/api/BaseAPI'

export default class LocationAPI extends BaseAPI {
  typeahead(query) {
    return this.$getv2('/location/typeahead?q=' + encodeURIComponent(query))
  }

  resolve(name) {
    // logError=false: a 404 (the name isn't a known place) is an expected,
    // non-error outcome used to decide whether to offer "search near <place>".
    return this.$getv2('/location/resolve', { name }, false)
  }

  latlng(lat, lng) {
    return this.$getv2('/location/latlng', {
      lat,
      lng,
    })
  }

  fetch(params) {
    return this.$getv2('/locations', params)
  }

  fetchv2(id) {
    return this.$getv2('/location/' + id)
  }

  fetchAddresses(id) {
    return this.$getv2('/location/' + id + '/addresses')
  }

  add(data) {
    return this.$putv2('/locations', data)
  }

  update(data) {
    return this.$patchv2('/locations', data)
  }

  del(id, groupid) {
    return this.$postv2('/locations', {
      id,
      action: 'Exclude',
      byname: false,
      groupid,
    })
  }

  convertKML(kml) {
    return this.$postv2('/locations/kml', {
      action: 'ConvertKML',
      kml,
    })
  }
}
