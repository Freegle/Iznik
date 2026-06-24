<template>
  <div class="partnerships">
    <!-- ── Hero ─────────────────────────────────────────────── -->
    <section class="partnerships__hero">
      <h1>Freegle supports charities &amp; community organisations</h1>
      <p class="partnerships__hero-sub">Helping you, and the people you work with.</p>
      <p class="partnerships__strapline">
        Every day in your local community, freeglers are giving things away for
        free. Freegle connects people to the things they need, locally and at no
        cost.
      </p>
      <p class="partnerships__strapline partnerships__strapline--lead">
        And there&rsquo;s plenty Freegle can do to support your organisation too.
      </p>

      <div class="partnerships__hero-actions">
        <nuxt-link to="/charity" class="partnerships__btn partnerships__btn--primary">
          <v-icon icon="star" />
          Become a charity partner
        </nuxt-link>
        <nuxt-link to="/find" class="partnerships__btn partnerships__btn--outline">
          <v-icon icon="search" />
          Ask for something
        </nuxt-link>
        <nuxt-link to="/give" class="partnerships__btn partnerships__btn--outline">
          <v-icon icon="gift" />
          Give something away
        </nuxt-link>
      </div>
    </section>

    <!-- ── Give & Get ───────────────────────────────────────── -->
    <section class="partnerships__section partnerships__section--highlight">
      <h2>Individuals and organisations can give and get with Freegle</h2>
      <div class="partnerships__giveget">
        <div class="partnerships__giveget-item">
          <div class="partnerships__giveget-icon partnerships__giveget-icon--give">
            <v-icon icon="gift" />
          </div>
          <h3>Got something you no longer need?</h3>
          <p>Post it on Freegle and someone nearby will arrange to collect it.</p>
        </div>
        <div class="partnerships__giveget-item">
          <div class="partnerships__giveget-icon partnerships__giveget-icon--get">
            <v-icon icon="hand-holding-heart" />
          </div>
          <h3>Need something for your organisation or the people you support?</h3>
          <p>Just ask! Others nearby will get in contact if they can help.</p>
        </div>
      </div>
      <p class="partnerships__giveget-free">Everything is free and always will be!</p>
    </section>

    <!-- ── Video ────────────────────────────────────────────── -->
    <!-- Hidden until the consolidated video exists: flip showVideo to re-enable. -->
    <section v-if="showVideo" class="partnerships__section partnerships__video">
      <h2>Freegle helps organisations and the people you support</h2>
      <!-- Video placeholder: replace with the embedded consolidated video when ready. -->
      <div class="partnerships__video-frame">
        <div class="partnerships__video-play">
          <v-icon icon="play" />
        </div>
        <span class="partnerships__video-label">Single consolidated video</span>
      </div>
      <div class="partnerships__chapters">
        <span
          v-for="chapter in chapters"
          :key="chapter.time"
          class="partnerships__chapter"
        >
          <span class="partnerships__chapter-time">{{ chapter.time }}</span>
          {{ chapter.label }}
        </span>
      </div>
    </section>

    <!-- ── Testimonial + logos ──────────────────────────────── -->
    <!-- Hidden until we have a real testimonial and partner logos: flip showTestimonial. -->
    <section
      v-if="showTestimonial"
      class="partnerships__section partnerships__testimonial"
    >
      <blockquote class="partnerships__quote">
        &ldquo;Freegle helped us furnish our community centre and find volunteers
        we didn&rsquo;t know existed locally.&rdquo;
      </blockquote>
      <p class="partnerships__quote-by">&mdash; Name, Organisation name, Town</p>
      <div class="partnerships__logos">
        <!-- Placeholder logo strip: swap for real partner logos (greyscale). -->
        <div
          v-for="logo in logoPlaceholders"
          :key="logo"
          class="partnerships__logo"
        >
          <v-icon :icon="logo" />
        </div>
      </div>
      <p class="partnerships__logos-caption">
        Trusted by charities and community groups across the UK.
      </p>
    </section>

    <!-- ── What Freegle can do (cards) ──────────────────────── -->
    <section class="partnerships__offer">
      <div class="partnerships__offer-banner">
        <h2>Here&rsquo;s what Freegle can do for your organisation</h2>
        <p>Click to find out more and get started.</p>
      </div>

      <div class="partnerships__cards">
        <nuxt-link
          v-for="card in cards"
          :key="card.key"
          :to="card.to"
          class="partnerships__card"
          :class="`partnerships__card--${card.key}`"
        >
          <span class="partnerships__card-tag">
            <v-icon :icon="card.icon" />
            {{ card.tag }}
          </span>
          <h3>{{ card.title }}</h3>
          <p>{{ card.text }}</p>
          <span class="partnerships__card-go">
            {{ card.cta }}
            <v-icon icon="arrow-right" />
          </span>
        </nuxt-link>

        <ExternalLink
          href="mailto:partnerships@ilovefreegle.org?subject=Always%20Wanted%20-%20keep%20me%20posted"
          class="partnerships__card partnerships__card--soon"
        >
          <span class="partnerships__card-tag">
            <v-icon icon="clock" />
            Coming soon
          </span>
          <h3>Always Wanted</h3>
          <p>
            A new feature in development &mdash; set a standing request for things
            you always need.
          </p>
          <span class="partnerships__card-go">
            Join the mailing list to hear first
            <v-icon icon="arrow-right" />
          </span>
        </ExternalLink>
      </div>
    </section>

    <!-- ── Team ─────────────────────────────────────────────── -->
    <section class="partnerships__section partnerships__team-section">
      <h2>Meet the partnerships team</h2>
      <p class="partnerships__team-intro">
        Our partnerships programme is delivered by a dedicated team with deep
        expertise in waste prevention, reuse and community engagement.
      </p>
      <div class="partnerships__team">
        <div
          v-for="member in team"
          :key="member.name"
          class="partnerships__member"
        >
          <img
            :src="member.photo"
            :alt="member.name"
            class="partnerships__member-photo"
          />
          <div class="partnerships__member-info">
            <div class="partnerships__member-top">
              <h3>{{ member.name }}</h3>
              <p class="partnerships__member-role">
                {{ member.role }}
              </p>
              <p class="partnerships__member-bio">
                {{ member.bio }}
              </p>
            </div>
            <div class="partnerships__member-bottom">
              <div
                v-if="member.freegleStory"
                class="partnerships__member-story"
              >
                <span class="partnerships__story-label">Favourite freegle</span>
                <p>&ldquo;{{ member.freegleStory }}&rdquo;</p>
              </div>
              <ExternalLink
                v-if="member.linkedin"
                :href="member.linkedin"
                class="partnerships__linkedin"
              >
                <v-icon :icon="['fab', 'linkedin']" />
                <span>Connect on LinkedIn</span>
              </ExternalLink>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Get in touch ─────────────────────────────────────── -->
    <section class="partnerships__section partnerships__section--cta">
      <h2>Get in touch</h2>
      <p>
        Find out more about how Freegle could work for and with your
        organisation &mdash; we&rsquo;re here to help.
      </p>
      <ExternalLink
        href="mailto:partnerships@ilovefreegle.org"
        class="partnerships__contact-btn"
      >
        <v-icon icon="envelope" />
        Contact the Freegle Partnerships Team
      </ExternalLink>
    </section>
  </div>
</template>
<script setup>
import { buildHead } from '~/composables/useBuildHead'
import { useRoute } from '#imports'
import ExternalLink from '~/components/ExternalLink.vue'

const runtimeConfig = useRuntimeConfig()
const route = useRoute()

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Partnerships',
    'Freegle supports charities and community organisations. Ask for what you need, give what you no longer want, recruit volunteers and promote your events — all free.'
  )
)

/* No consolidated video yet — keep the section code but hidden. */
const showVideo = false

/* No real testimonial or partner logos yet — keep the section code but hidden. */
const showTestimonial = false

const chapters = [
  { time: '0:00', label: 'Freegle & your organisation' },
  { time: '0:50', label: 'Giving something away' },
  { time: '1:30', label: 'Looking for something' },
  { time: '2:10', label: 'How we can help you' },
]

const logoPlaceholders = ['users', 'leaf', 'heart', 'star', 'comments']

const cards = [
  {
    key: 'charity',
    tag: 'Charity Partner',
    icon: 'star',
    title: 'Register as a Charity Partner',
    text: 'Get recognised status on Freegle — your posts carry trusted credibility and you can promote your good work to your local community, for free.',
    to: '/charity',
    cta: 'Register your charity',
  },
  {
    key: 'wanted',
    tag: 'WANTED',
    icon: 'search',
    title: 'Ask for stuff',
    text: "Post a WANTED on behalf of your organisation or the people you support — whether it's furniture, clothing, household items, equipment, supplies, or anything else you need.",
    to: '/find',
    cta: 'Post a WANTED',
  },
  {
    key: 'offer',
    tag: 'OFFER',
    icon: 'gift',
    title: 'Give stuff away',
    text: 'Got surplus stock, equipment or items your organisation no longer needs? Offer them to your local community so they get reused, not wasted.',
    to: '/give',
    cta: 'Post an OFFER',
  },
  {
    key: 'volunteer',
    tag: 'Volunteer Opportunities',
    icon: 'hands-helping',
    title: 'Post a volunteer opportunity',
    text: 'Are you a charity or good cause that needs volunteers? Ask our lovely community of freeglers to help.',
    to: '/volunteerings',
    cta: 'Post an opportunity',
  },
  {
    key: 'events',
    tag: 'Community Events',
    icon: 'calendar-alt',
    title: 'Promote your community events',
    text: 'Let people in your area know about upcoming events and activities by listing them for free on Freegle.',
    to: '/communityevents',
    cta: 'Add an event',
  },
]

const team = [
  {
    name: 'Natalie Ibbott',
    role: 'Councils & Partnerships Project Manager',
    photo: '/partnerships/natalie-ibbott.jpg',
    bio: 'With 20+ years in waste, reuse, recycling and circular economy, with experience across local government, regional partnerships and consultancy, Natalie has worked on projects for Arts Council England, ReLondon, Canary Wharf Group and numerous local authorities. She specialises in strategy, project management and partnership working.',
    freegleStory:
      "I posted a Wanted for topiary shears, definitely not expecting a reply — but my local community didn't let me down. A few hours later I had an old but perfectly functioning pair!",
    linkedin: 'https://www.linkedin.com/in/natalieibbott/',
  },
  {
    name: 'Anna Carmichael',
    role: 'Councils & Partnerships Project Manager',
    photo: '/partnerships/anna-carmichael.jpg',
    bio: '20+ years in waste prevention, environmental education and sustainability across local government, schools, regional partnerships and the not-for-profit sector. Specialises in building relationships, behaviour change and creating engaging content that inspires positive action.',
    freegleStory:
      "I sourced a huge bag of odd socks from my local Freegle community for a children's sock-puppet workshop — within hours! The donor said it gave him just the nudge he needed to clear out his sock drawer.",
    linkedin: 'https://www.linkedin.com/in/anna-c-carmichael/',
  },
  {
    name: 'Edward Hibbert',
    role: 'Co-Founder & CTO',
    photo: '/partnerships/edward-hibbert.jpg',
    bio: 'Builds software that increases kindness in the world. After a career in large-scale email and voicemail systems, Edward had a carefully planned midlife crisis and moved into tech-for-good. Board member of Volunteer Edinburgh and Give a Kidney, passionate about how volunteering enriches lives and organisations.',
    freegleStory:
      'An automatic potato peeler. I got carried away and collected it, then was too scared to use it. It sat in my kitchen staring at me reproachfully. So I freegled it on. Oh, and I freegled a kidney once.',
    linkedin: 'https://www.linkedin.com/in/edwardhibbert/',
  },
  {
    name: 'Cat Fletcher',
    role: 'Co-Founder & Media',
    photo: '/partnerships/cat-fletcher.jpg',
    bio: 'Award-winning waste-prevention activist, researcher and practitioner. Cat co-founded Freegle in 2009 and leads all publicity. A driving force behind the multi-award-winning Waste House at the University of Brighton, she has run the Brighton Freegle Free Shop since 2021, runs Planet Brighton environmental hub and advises councils, the NHS and corporates on reuse and circular economy.',
    freegleStory:
      'After my house accidentally burnt down, I was able to get a new wardrobe and all the essentials I needed literally the next day — thanks to the kindness of freeglers.',
    linkedin: 'https://www.linkedin.com/in/cat-fletcher-uk/',
  },
]
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.partnerships {
  max-width: 960px;
  margin: 0 auto;
  padding: 1rem;

  @include media-breakpoint-up(md) {
    padding: 2rem;
  }
}

/* ── Hero ─────────────────────────────────────────────────── */

.partnerships__hero {
  text-align: center;
  padding: 2.5rem 1.5rem;
  margin-bottom: 1rem;
  background: linear-gradient(135deg, $color-green--bg-gradient 0%, white 100%);
  border-bottom: 3px solid $color-green-background;

  h1 {
    font-size: 1.625rem;
    font-weight: 700;
    color: $color-header;
    margin-bottom: 0.5rem;
    line-height: 1.25;

    @include media-breakpoint-up(md) {
      font-size: 2.375rem;
    }
  }
}

.partnerships__hero-sub {
  font-size: 1.0625rem;
  font-weight: 600;
  color: $color-green--dark;
  margin-bottom: 1rem;

  @include media-breakpoint-up(md) {
    font-size: 1.25rem;
  }
}

.partnerships__strapline {
  font-size: 1rem;
  line-height: 1.65;
  color: $gray-700;
  max-width: 620px;
  margin: 0 auto 0.75rem;

  @include media-breakpoint-up(md) {
    font-size: 1.0625rem;
  }
}

.partnerships__strapline--lead {
  font-weight: 600;
  color: $color-header;
}

.partnerships__hero-actions {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.625rem;
  max-width: 340px;
  margin: 1.5rem auto 0;

  @include media-breakpoint-up(md) {
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    max-width: none;
  }
}

.partnerships__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.7rem 1.5rem;
  font-size: 1rem;
  font-weight: 600;
  text-decoration: none;
  border: 2px solid $color-green-background;
  transition: all 0.18s ease;

  &:hover {
    text-decoration: none;
    transform: translateY(-1px);
  }
}

.partnerships__btn--primary {
  background: $color-green-background;
  color: white;

  &:hover {
    background: darken($color-green-background, 8%);
    border-color: darken($color-green-background, 8%);
    color: white;
  }
}

.partnerships__btn--outline {
  background: white;
  color: $color-header;

  &:hover {
    background: $color-green--bg-gradient;
    color: $color-header;
  }
}

/* ── Sections ─────────────────────────────────────────────── */

.partnerships__section {
  background: white;
  padding: 1.5rem;
  margin-bottom: 1rem;
  box-shadow: var(--shadow-sm);

  @include media-breakpoint-up(md) {
    padding: 2rem;
  }

  > h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: $color-header;
    margin-bottom: 1rem;
    text-align: center;

    @include media-breakpoint-up(md) {
      font-size: 1.5rem;
    }
  }
}

.partnerships__section--highlight {
  background: linear-gradient(135deg, $color-green--bg-gradient 0%, white 100%);
}

/* ── Give & Get ───────────────────────────────────────────── */

.partnerships__giveget {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;

  @include media-breakpoint-up(md) {
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
  }
}

.partnerships__giveget-item {
  text-align: center;

  h3 {
    font-size: 1.0625rem;
    font-weight: 700;
    color: $gray-800;
    margin-bottom: 0.5rem;
  }

  p {
    font-size: 0.9375rem;
    line-height: 1.6;
    color: $gray-700;
    margin: 0;
  }
}

.partnerships__giveget-icon {
  width: 3.25rem;
  height: 3.25rem;
  margin: 0 auto 0.875rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  font-size: 1.375rem;
}

.partnerships__giveget-icon--give {
  background: rgba($color-green-background, 0.15);
  color: $color-green-background;
}

.partnerships__giveget-icon--get {
  background: rgba($color-secondary, 0.15);
  color: $color-secondary;
}

.partnerships__giveget-free {
  text-align: center;
  font-size: 1.0625rem;
  font-weight: 700;
  color: $color-header;
  margin: 1.5rem 0 0;
}

/* ── Video ────────────────────────────────────────────────── */

.partnerships__video-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  background: linear-gradient(135deg, #243018 0%, #3b5226 100%);
  cursor: pointer;
  overflow: hidden;
}

.partnerships__video-play {
  width: 4.5rem;
  height: 4.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: $color-white-opacity-90;
  color: $color-green-background;
  font-size: 1.75rem;
  padding-left: 0.35rem;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
  transition: transform 0.18s ease;

  .partnerships__video-frame:hover & {
    transform: scale(1.08);
  }
}

.partnerships__video-label {
  color: $color-white-opacity-90;
  font-size: 0.9375rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.partnerships__chapters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 1rem;
}

.partnerships__chapter {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.75rem;
  font-size: 0.8125rem;
  color: $gray-700;
  background: $color-gray--lighter;
  border: 1px solid $color-gray-3;
}

.partnerships__chapter-time {
  font-weight: 700;
  color: $color-green--dark;
}

/* ── Testimonial + logos ──────────────────────────────────── */

.partnerships__testimonial {
  text-align: center;
}

.partnerships__quote {
  font-size: 1.1875rem;
  line-height: 1.55;
  font-weight: 600;
  font-style: italic;
  color: $color-header;
  max-width: 640px;
  margin: 0 auto 0.75rem;

  @include media-breakpoint-up(md) {
    font-size: 1.375rem;
  }
}

.partnerships__quote-by {
  font-size: 0.9375rem;
  color: $gray-600;
  margin-bottom: 1.75rem;
}

.partnerships__logos {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.75rem;
}

.partnerships__logo {
  width: 5.25rem;
  height: 3rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  color: $color-gray--base;
  background: $color-gray--lighter;
  border: 1px solid $color-gray-3;
  filter: grayscale(1);
}

.partnerships__logos-caption {
  font-size: 0.8125rem;
  color: $color-gray--normal;
  margin: 0.875rem 0 0;
}

/* ── Offer / cards ────────────────────────────────────────── */

.partnerships__offer {
  margin-bottom: 1rem;
}

.partnerships__offer-banner {
  text-align: center;
  padding: 1.5rem;
  background: $color-header;
  color: white;

  @include media-breakpoint-up(md) {
    padding: 1.75rem;
  }

  h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: white !important;
    margin-bottom: 0.25rem;

    @include media-breakpoint-up(md) {
      font-size: 1.5rem;
    }
  }

  p {
    font-size: 0.9375rem;
    color: $color-white-opacity-90;
    margin: 0;
  }
}

.partnerships__cards {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.875rem;
  margin-top: 0.875rem;

  @include media-breakpoint-up(md) {
    grid-template-columns: 1fr 1fr;
  }
}

.partnerships__card {
  display: flex;
  flex-direction: column;
  padding: 1.375rem;
  background: white;
  border-top: 4px solid $color-gray--light;
  box-shadow: var(--shadow-sm);
  text-decoration: none;
  transition: transform 0.18s ease, box-shadow 0.18s ease;

  &:hover {
    text-decoration: none;
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
  }

  h3 {
    font-size: 1.0625rem;
    font-weight: 700;
    color: $gray-800;
    margin-bottom: 0.5rem;
  }

  p {
    font-size: 0.875rem;
    line-height: 1.6;
    color: $gray-700;
    margin: 0 0 1rem;
    flex: 1;
  }
}

.partnerships__card-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  align-self: flex-start;
  padding: 0.25rem 0.7rem;
  margin-bottom: 0.75rem;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-radius: 2rem;
}

.partnerships__card-go {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: $color-header;

  svg {
    transition: transform 0.18s ease;
  }

  .partnerships__card:hover & svg {
    transform: translateX(3px);
  }
}

/* Per-card accent colours (the action boxes stand out from the page) */

@mixin card-accent($color, $tint) {
  border-top-color: $color;
  background: $tint;

  .partnerships__card-tag {
    background: rgba($color, 0.15);
    color: $color;
  }

  .partnerships__card-go {
    color: $color;
  }
}

.partnerships__card--charity {
  @include card-accent($color-green-background, #f1f8ec);
}

.partnerships__card--wanted {
  @include card-accent($color-secondary, #ecf6fa);
}

.partnerships__card--offer {
  @include card-accent($color-warning, #fdf4e9);
}

.partnerships__card--volunteer {
  @include card-accent($color-green--dark, #ecf4f2);
}

.partnerships__card--events {
  @include card-accent($color-blue--light, #eef4fb);
}

.partnerships__card--soon {
  @include card-accent($color-gray--normal, $color-gray--lighter);
}

/* ── Team ─────────────────────────────────────────────────── */

.partnerships__team-intro {
  text-align: center;
  font-size: 0.9375rem;
  line-height: 1.7;
  color: $gray-700;
  max-width: 640px;
  margin: 0 auto;
}

.partnerships__team {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
  margin-top: 1.5rem;

  @include media-breakpoint-up(md) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.partnerships__member {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 1.5rem;
  background: $gray-100;

  @include media-breakpoint-up(md) {
    padding: 1.75rem;
  }
}

.partnerships__member-photo {
  width: 110px;
  height: 110px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid white;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
  margin-bottom: 1rem;
}

.partnerships__member-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-self: stretch;

  h3 {
    font-size: 1.0625rem;
    font-weight: 700;
    color: $gray-800;
    margin-bottom: 0.125rem;
  }
}

.partnerships__member-top {
  flex: 1;
}

.partnerships__member-bottom {
  margin-top: auto;
}

.partnerships__member-role {
  font-size: 0.8125rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: $color-green-background;
  margin-bottom: 0.75rem;
}

.partnerships__member-bio {
  font-size: 0.875rem;
  line-height: 1.65;
  color: $gray-700;
  margin-bottom: 0;
}

.partnerships__member-story {
  padding: 0.625rem 0.75rem;
  background: white;
  margin-bottom: 0.75rem;

  p {
    font-size: 0.8125rem;
    line-height: 1.6;
    color: $gray-600;
    font-style: italic;
    margin: 0;
  }
}

.partnerships__story-label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $color-green-background;
  margin-bottom: 0.25rem;
}

.partnerships__linkedin {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.875rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: #0a66c2;
  background: #e8f0fe;
  border-radius: 2rem;
  text-decoration: none;
  transition: all 0.15s ease;

  &:hover {
    background: #0a66c2;
    color: white;
    text-decoration: none;
  }
}

/* ── CTA ──────────────────────────────────────────────────── */

.partnerships__section--cta {
  text-align: center;
  background: linear-gradient(135deg, $color-green--bg-gradient 0%, white 100%);
  padding: 2rem 1.5rem;

  @include media-breakpoint-up(md) {
    padding: 2.5rem;
  }

  > p {
    font-size: 1rem;
    line-height: 1.65;
    color: $gray-700;
    max-width: 540px;
    margin: 0 auto 1.5rem;
  }
}

.partnerships__contact-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1.0625rem;
  font-weight: 600;
  color: white;
  background: $color-green-background;
  text-decoration: none;
  padding: 0.8rem 1.85rem;
  border: 2px solid $color-green-background;
  transition: all 0.2s ease;

  &:hover {
    background: darken($color-green-background, 8%);
    border-color: darken($color-green-background, 8%);
    color: white;
    text-decoration: none;
  }
}
</style>
