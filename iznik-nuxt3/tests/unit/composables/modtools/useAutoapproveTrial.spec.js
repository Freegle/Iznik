import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

const mockAuthStore = { groups: [] }
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

const { useAutoapproveTrial, useAutomod } =
  await import('~/modtools/composables/useAutoapproveTrial')

describe('useAutoapproveTrial', () => {
  beforeEach(() => {
    mockAuthStore.groups = [
      { groupid: 1, autoapprovetrial: true, automod: true },
      { groupid: 2 },
      { groupid: 3, automod: true },
    ]
  })

  it('is in the trial when any of my communities is', () => {
    expect(useAutoapproveTrial().inTrial.value).toBe(true)
    mockAuthStore.groups = [{ groupid: 2 }]
    expect(useAutoapproveTrial().inTrial.value).toBe(false)
  })

  // A help box on a community outside the trial must not describe a countdown that
  // community will never show, even to a moderator who also runs a trial community.
  it('narrows to one community when asked', () => {
    expect(useAutoapproveTrial(1).inTrial.value).toBe(true)
    expect(useAutoapproveTrial(2).inTrial.value).toBe(false)
    expect(useAutoapproveTrial(0).inTrial.value).toBe(true)
  })

  it('follows a ref or getter for the community', () => {
    const gid = ref(2)
    const { inTrial } = useAutoapproveTrial(gid)
    expect(inTrial.value).toBe(false)
    gid.value = 1
    expect(inTrial.value).toBe(true)
    expect(useAutoapproveTrial(() => '1').inTrial.value).toBe(true)
  })

  it('answers isTrialGroup for a number or a string id', () => {
    const { isTrialGroup } = useAutoapproveTrial()
    expect(isTrialGroup(1)).toBe(true)
    expect(isTrialGroup('1')).toBe(true)
    expect(isTrialGroup(2)).toBe(false)
    expect(isTrialGroup(99)).toBe(false)
  })

  it('copes with no groups at all', () => {
    mockAuthStore.groups = null
    expect(useAutoapproveTrial().inTrial.value).toBe(false)
    expect(useAutoapproveTrial().isTrialGroup(1)).toBe(false)
    expect(useAutoapproveTrial().trialGroupIds.value).toEqual([])
  })

  // The Check page offers only these: no other community can have a Check queue.
  it('lists the communities in the trial, as numbers', () => {
    expect(useAutoapproveTrial().trialGroupIds.value).toEqual([1])
    mockAuthStore.groups = [
      { groupid: '5', autoapprovetrial: true },
      { groupid: 6 },
      { groupid: 7, autoapprovetrial: true },
    ]
    expect(useAutoapproveTrial().trialGroupIds.value).toEqual([5, 7])
    mockAuthStore.groups = [{ groupid: 2 }]
    expect(useAutoapproveTrial().trialGroupIds.value).toEqual([])
  })

  it('does not narrow the list to a community, which is for the help boxes', () => {
    expect(useAutoapproveTrial(2).trialGroupIds.value).toEqual([1])
  })
})

describe('useAutomod', () => {
  it('is true for a community in either automod list', () => {
    mockAuthStore.groups = [
      { groupid: 1, autoapprovetrial: true, automod: true },
      { groupid: 2 },
      { groupid: 3, automod: true },
    ]
    const { isAutomodGroup } = useAutomod()
    expect(isAutomodGroup(1)).toBe(true)
    expect(isAutomodGroup(3)).toBe(true)
    expect(isAutomodGroup('3')).toBe(true)
    expect(isAutomodGroup(2)).toBe(false)
  })
})
