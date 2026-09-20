import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import SlugRedirect from '~/modtools/pages/modtools/[...slug].vue'

// The page reads the route once at setup and redirects 2s later. Tests drive
// both halves: what the route says at mount, and what the router is asked for
// when the timer fires.
//
// The route has to be reactive, as vue-router's is. With a plain object a
// navigation mid-test leaves any computed over it cached, so the bug this spec
// exists for cannot reproduce.
let currentRoute
const push = vi.fn()

globalThis.__testUseRoute = () => currentRoute
globalThis.__testUseRouter = () => ({ push, replace: vi.fn() })

function mountPage() {
  return mount(SlugRedirect, {
    global: {
      stubs: {
        NuxtLink: {
          props: ['to'],
          template: '<a class="link" :href="to"><slot /></a>',
        },
      },
    },
  })
}

describe('modtools catch-all redirect page', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    push.mockClear()
    currentRoute = reactive({
      name: 'modtools-slug',
      path: '/modtools/members/review',
      params: { slug: ['members', 'review'] },
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('strips the /modtools prefix and redirects after 2s', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('/members/review')
    expect(push).not.toHaveBeenCalled()

    vi.advanceTimersByTime(2000)

    expect(push).toHaveBeenCalledWith('/members/review')
  })

  it('redirects the bare /modtools URL to the root', () => {
    // vue-router gives a catch-all param of '' - not an array - when nothing
    // follows /modtools.
    currentRoute = reactive({
      name: 'modtools-slug',
      path: '/modtools',
      params: { slug: '' },
    })

    const wrapper = mountPage()
    expect(wrapper.text()).toContain('/')

    vi.advanceTimersByTime(2000)

    expect(push).toHaveBeenCalledWith('/')
  })

  it('does not throw when the route moves on before the redirect fires', () => {
    // The crash in Sentry (issues 7683112005 / 7437050282, 800+ events, all on
    // https://modtools.org/modtools). The page derived its target from the live
    // global route, so once the router had left this catch-all - params.slug
    // then being undefined - the pending timer blew up with "can't access
    // property Symbol.iterator, s.params.slug is undefined" and the moderator
    // got the error page instead of a redirect.
    const wrapper = mountPage()

    // Navigate away, as the router really does: same reactive route object,
    // new contents. params.slug goes with the catch-all that no longer matches.
    currentRoute.name = 'index'
    currentRoute.path = '/'
    currentRoute.params = {}

    expect(() => vi.advanceTimersByTime(2000)).not.toThrow()
    expect(push).toHaveBeenCalledWith('/members/review')

    wrapper.unmount()
  })

  it('cancels the redirect if the page is torn down first', () => {
    const wrapper = mountPage()

    wrapper.unmount()
    vi.advanceTimersByTime(2000)

    expect(push).not.toHaveBeenCalled()
  })
})
