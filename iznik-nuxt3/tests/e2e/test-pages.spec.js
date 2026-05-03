const { test, expect } = require('./fixtures')

// Intercept PayPal SDK so DonationButton.makeButton() always runs regardless
// of PayPal CDN response time — keeps coverage deterministic across CI runs.
test.beforeEach(async ({ page: pageObj }) => {
  await pageObj.route('**paypalobjects.com**', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: `window.PayPal={Donation:{Button:function(cfg){return{render:function(s){}}}}}`,
    })
  })
})

const publicPages = [
  { path: '/', title: "Don't throw it away, give it away!" },
  { path: '/about', title: 'About Us' },
  { path: '/terms', title: 'Terms of Use' },
  { path: '/privacy', title: 'Privacy' },
  { path: '/help', title: 'Help' },
  { path: '/donate', title: 'Donate to Freegle' },
  { path: '/disclaimer', title: 'Disclaimer' },
  { path: '/forgot', title: 'Lost Password' },
  { path: '/jobs', title: 'Jobs' },
  { path: '/communityevents', title: 'Community Events' },
  { path: '/volunteerings', title: 'Volunteer Opportunities' },
  { path: '/stories', title: 'Stories' },
  { path: '/stats', title: 'Statistics' },
  { path: '/stats/authorities', title: 'Statistics by Authority' },
  { path: '/stats/heatmap', title: 'Heatmap' },
  { path: '/promote', title: 'Promote Freegle' },
  { path: '/mobile', title: 'Our mobile app' },
  { path: '/giftaid', title: 'Gift Aid' },
  { path: '/stories/summary', title: 'Story summary' },
  { path: '/shortlinks', title: 'Freegle' },
  { path: '/NationalReuseDay', title: 'National Reuse Day' },
  { path: '/unsubscribe/unsubscribed', title: 'Freegle' },
]

test.describe('Public pages tests', () => {
  for (const page of publicPages) {
    test(`${page.path} should load without console errors`, async ({
      page: pageObj,
      waitForNuxtPageLoad,
    }) => {
      await pageObj.gotoAndVerify(page.path)
      await waitForNuxtPageLoad({ timeout: 30000 })

      const title = await pageObj.title()
      expect(title).toContain(page.title)
    })
  }
})
