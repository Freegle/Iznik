import { setupModMessages } from '~/composables/useModMessages'

const typeOptions = computed(() => {
  const { group } = setupModMessages()
  const thegroup = group.value
  return [
    {
      value: 'Offer',
      text:
        thegroup &&
        thegroup.settings &&
        thegroup.settings.keywords &&
        thegroup.settings.keywords.offer
          ? thegroup.settings.keywords.offer
          : 'OFFER',
    },
    {
      value: 'Wanted',
      text:
        thegroup &&
        thegroup.settings &&
        thegroup.settings.keywords &&
        thegroup.settings.keywords.wanted
          ? thegroup.settings.keywords.wanted
          : 'WANTED',
    },
  ]
})

export function setupKeywords() {
  return {
    typeOptions,
  }
}
