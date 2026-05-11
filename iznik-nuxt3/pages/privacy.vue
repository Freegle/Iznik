<template>
  <div class="privacy-page">
    <div class="privacy-page__content">
      <client-only>
        <h2>1. What data we process</h2>
        <p>
          We process your email address, name, approximate location, and profile
          picture. If you log in using a social login (Facebook/Google/Yahoo)
          then we will obtain these details from them.
        </p>
        <p>
          All OFFER/WANTED posts are public, so if you put phone numbers, email
          addresses or postal addresses into them, other people will be able to
          see them. If you're concerned about this, don't do it!
        </p>
        <h2>2. How we process data</h2>
        <p>
          We use your email address to send you various emails about your posts
          and posts from other freeglers. You can change the emails you receive
          or turn them off from
          <!-- eslint-disable-next-line-->
          <nuxt-link no-prefetch to="/settings">Settings</nuxt-link>.
          <!-- eslint-disable-next-line-->
          We won't show your email address to any organisation outside Freegle,
          and other freeglers won't see it (unless your community leaves Freegle
          - see below).
        </p>
        <p>
          We show your name, profile picture and approximate location to other
          freeglers to make the site more engaging and show that freeglers are
          real people. If you don't want that, you can change it in
          <!-- eslint-disable-next-line-->
          <nuxt-link no-prefetch to="/settings">Settings</nuxt-link>.
        </p>
        <p>
          We try to protect you from spammers and fraudsters. Sometimes our
          volunteers may read what you write in Chat to other freeglers to check
          that it's not spam, phishing or fraud. If we happen to find something
          which is against Freegle rules, we may take further action.
        </p>
        <p>
          So that potential new users can see what is being offered on Freegle,
          we make some details of posts public, i.e. the item name, approximate
          location, the description and any photos. No personal details are made
          public unless in this information.
        </p>
        <h2>3. Cookies and Tracking</h2>
        <p>
          You'll have seen lots of irritating popups on websites about consent
          for cookies. We have one of those too. Sorry, but it's the law. Here
          are some technical details.
        </p>
        <h3>3.1 Necessary function</h3>
        <p>
          We store some things which are strictly necessary to make the site
          work.
        </p>
        <ul>
          <li>
            We store cookies for sessions and for your cookie preferences. Those
            are needed for the site to work.
          </li>
          <li>
            We store some information in your browser's local storage. This
            contains a few settings and is also used when you're replying to a
            post. That's like the "shopping cart" example in the Information
            Commissioner's
            <!-- eslint-disable-next-line -->
            <ExternalLink
              href="https://ico.org.uk/for-organisations/guide-to-pecr/cookies-and-similar-technologies/"
              >advice</ExternalLink
            >
            and does not require consent.
          </li>
          <li>
            We use Google and Facebook's buttons to allow you to log in. They
            store a few third-party cookies of their own, which are strictly
            necessary to make those buttons work.
          </li>
          <li>
            We use
            <ExternalLink href="https://matomo.org">Matomo</ExternalLink> to
            collect information about how people use the site. This is similar
            to Google Analytics, but more privacy-safe. We have configured this
            not to use any cookies.
          </li>
          <li>
            We record information about your browser/device and how you use the
            site. This helps diagnose problems and make the site easier to use.
            We don't pass anything identifying you to other people.
          </li>
        </ul>
        <h3>3.2 Optional function</h3>
        <p>We store some other things which help make the site better.</p>
        <ul>
          <li>
            When something goes wrong on the website, we log it to a third party
            site called
            <!-- eslint-disable-next-line -->
            <ExternalLink href="https://sentry.io">Sentry</ExternalLink>. They
            help organise the logs and make it easier for us to identify
            problems. This
            <!-- eslint-disable-next-line -->
            <ExternalLink
              href="https://docs.sentry.io/data-management/sensitive-data/"
              >should not include</ExternalLink
            >
            any of your personal data. You can read their privacy policy
            <!-- eslint-disable-next-line -->
            <ExternalLink href="https://sentry.io/privacy/">here</ExternalLink>.
          </li>
          <li>
            We show job ads from
            <ExternalLink href="https://uk.whatjobs.com/">WhatJobs</ExternalLink
            >, both on the site and in emails. We'd prefer not to, but we don't
            raise enough from
            <nuxt-link no-prefetch to="/donate">donations</nuxt-link> alone to
            keep going. We don't pass them any information at all unless you
            click on one of the job links, not even your IP address.
          </li>
          <li>
            We also show ads from selected partners, using Google's advertising
            service. You can read more about which cookies Google uses
            <ExternalLink href="https://business.safety.google/adscookies/"
              >here</ExternalLink
            >.
          </li>
          <li>
            All or partial advertising on this Website or App is managed by
            Playwire LLC. If Playwire publisher advertising services are used,
            Playwire LLC may collect and use certain aggregated and anonymized
            data for advertising purposes. To learn more about the types of data
            collected, how data is used and your choices as a user, please visit
            <ExternalLink href="https://www.playwire.com/privacy-policy"
              >https://www.playwire.com/privacy-policy</ExternalLink
            >.
          </li>
        </ul>
        <p>
          If you are concerned about these, you can also install a privacy
          blocker such as
          <!-- eslint-disable-next-line -->
          <ExternalLink href="https://getadblock.com/en/">AdBlock</ExternalLink>
          <!-- eslint-disable-next-line -->
          or
          <ExternalLink href="https://www.ghostery.com/">Ghostery</ExternalLink>
          and still use the site. Please remember that while Freegle is free to
          use, it's not free to run, so it helps if you
          <nuxt-link no-prefetch to="/donate">donate</nuxt-link>.
        </p>
        <h2>4. Unsubscribing and deleting your data</h2>
        <h3>4.1 Unsubscribing from emails</h3>
        <p>
          You can stop receiving emails from Freegle at any time. Either:
        </p>
        <ul>
          <li>
            Go to
            <!-- eslint-disable-next-line -->
            <nuxt-link no-prefetch to="/settings">Settings</nuxt-link>
            and change your email preferences there, or
          </li>
          <li>
            Click the <strong>Unsubscribe</strong> link at the bottom of any
            email we send you.
          </li>
        </ul>
        <h3>4.2 Deleting your account and data</h3>
        <p>
          You have the right to have all your personal data deleted. To do this:
        </p>
        <div class="privacy-page__delete-cta">
          <!-- eslint-disable-next-line -->
          <nuxt-link no-prefetch to="/unsubscribe" class="privacy-page__delete-btn">
            Unsubscribe / Delete my data
          </nuxt-link>
        </div>
        <p>This will permanently delete:</p>
        <ul>
          <li>Your name, email address and profile picture</li>
          <li>Your location and account settings</li>
          <li>Your posts, replies and chat messages</li>
        </ul>
        <p>
          All your personal data will be deleted within <strong>14 days</strong>
          of your request. If you change your mind before that deadline, you can
          log back in to reactivate your account.
        </p>
        <p>
          If you logged in using Facebook, you can also request deletion directly
          through Facebook: go to
          <strong>Settings &amp; Privacy → Settings → Your Facebook Information
          → Deactivation and Deletion</strong>.
        </p>
        <p>
          Your data is also deleted automatically if your account is inactive for
          six months.
        </p>
        <h2>5. Communities leaving Freegle</h2>
        <p>
          Freegle communities may in theory choose to leave Freegle and move
          somewhere else. This has never actually happened, but it's possible in
          theory. If this happens then we will be in touch with you to discuss
          what should happen to your details.
        </p>
        <h2>6. Legal Basis</h2>
        <p>We operate under the legal basis of Legitimate Interest.</p>
        <h2>Got questions?</h2>
        <p>
          Privacy is a complicated and important area, which we take seriously.
          If you have questions or concerns, please
          <nuxt-link no-prefetch to="/help"> contact us</nuxt-link>. Our
          registered address is 64a North Road, Ormesby, Great Yarmouth, Norfolk
          NR29 3LE.
        </p>
        <p>
          If you're unhappy, you can also contact the Information Commissioner's
          Office
          <ExternalLink href="https://ico.org.uk/make-a-complain"
            >here</ExternalLink
          >.
        </p>
        <h3>Change History</h3>
        <p>Here are the changes to this page.</p>
        <ul class>
          <li>
            10/05/2026: Restructure section 4 to clearly explain how to unsubscribe
            and request data deletion, with a prominent delete button, to satisfy
            Facebook Platform Terms 4.b.
          </li>
          <li>
            20/12/2025: Add mention of recording more information for diagnosing
            problems and optimising the site.
          </li>
          <li>26/02/2025: Add Playwire information for advertising.</li>
          <li>
            19/11/2024: Update after Facebook request to clarify what data we
            store and how we process it.
          </li>
          <li>
            24/08/2024: Update to mention legal basis, retention period,
            complain to ICO.
          </li>
          <li>
            15/04/2024: Update to mention case where a community leaves Freegle.
          </li>
          <li>
            04/01/2024: Remove mention of Norfolk Freegle, which is no longer a
            separate entity.
          </li>
          <li>20/09/2023: Various clarifications and typos.</li>
          <li>
            19/09/2023: We now have a cookie consent banner. Reinstate session
            coookie, which is in fact used sometimes.
          </li>
          <li>03/08/2023: Remove session cookie, which is no longer used.</li>
          <li>31/07/2023: Remove Inspectlet, which we no longer use.</li>
          <li>
            04/02/2023: Removed Google Analytics, which we no longer use. Add
            mention of cookies.
          </li>
          <li>
            04/10/2021: Added section on data deletion as per Facebook request.
          </li>
          <li>05/02/2021: Updated to remove PixFuture.</li>
          <li>04/02/2021: Updated to include PixFuture.</li>
          <li>
            01/03/2020: Updated for Norfolk Freegle, which is now hosted on the
            main site.
          </li>
          <li>06/01/2020: Add use of Inspectlet.</li>
          <li>31/12/2019: Explain why we don't have or need a cookie popup.</li>
          <li>29/12/2019: Add use of Sentry.</li>
        </ul>
      </client-only>
    </div>
  </div>
</template>
<script setup>
import { buildHead } from '~/composables/useBuildHead'
import { useRoute } from '#imports'
import ExternalLink from '~/components/ExternalLink'

// Setup page head
const runtimeConfig = useRuntimeConfig()
const route = useRoute()

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Privacy',
    'What data we keep and how we protect it'
  )
)
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.privacy-page {
  background: $color-gray--lighter;
  min-height: 100vh;
  padding: 1rem;

  @include media-breakpoint-up(md) {
    padding: 1.5rem;
  }
}

.privacy-page__content {
  max-width: 800px;
  margin: 0 auto;
  background: white;
  padding: 1.5rem;
  box-shadow: var(--shadow-sm);

  h2 {
    font-size: 1.25rem;
    font-weight: 600;
    color: $color-green--darker;
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;

    &:first-child {
      margin-top: 0;
    }
  }

  h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: $gray-700;
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
  }

  p {
    font-size: 0.95rem;
    line-height: 1.6;
    color: $gray-700;
    margin-bottom: 0.75rem;
  }

  ul {
    padding-left: 1.25rem;
    margin-bottom: 1rem;

    li {
      font-size: 0.95rem;
      line-height: 1.6;
      color: $gray-700;
      margin-bottom: 0.5rem;
    }
  }

  ol {
    padding-left: 1.25rem;
    margin-bottom: 1rem;

    li {
      font-size: 0.95rem;
      line-height: 1.6;
      color: $gray-700;
      margin-bottom: 0.5rem;
    }
  }
}

.privacy-page__delete-cta {
  margin: 1.25rem 0;
}

.privacy-page__delete-btn {
  display: inline-block;
  background-color: $color-green--darker;
  color: white !important;
  font-size: 1rem;
  font-weight: 600;
  padding: 0.75rem 1.5rem;
  border-radius: 6px;
  text-decoration: none !important;

  &:hover {
    background-color: darken($color-green--darker, 8%);
    color: white !important;
  }
}
</style>
