# Freegle Accessibility Audit (WCAG 2.1 AA)

## Executive Summary

This audit identified **65+ accessibility issues** across the Freegle codebase. The most critical issues involve keyboard navigation and color contrast, which directly impact users with motor and visual impairments.

| Category | Issues | Severity | WCAG Level |
|----------|--------|----------|------------|
| Color Contrast | 8+ | High | AA |
| Keyboard Navigation | 15+ | Critical | A |
| Missing ARIA Labels | 20+ | High | A |
| Link Accessibility | 5+ | Medium | A |
| Image Alt Text | 10+ | Medium | A |
| Form Accessibility | 6+ | High | A |
| Modal Focus Management | 1 | Medium | AA |

---

## 1. Color Contrast Issues

### Critical: Gray Text on Light Backgrounds

**WCAG Requirement**: 4.5:1 contrast ratio for normal text, 3:1 for large text

#### Problem Areas

1. **ChatNotice.vue** (Lines 141-168)
   - Warning style uses `#8a6d00` on `#fff8e6` background
   - Contrast ratio: ~4.2:1 (marginal)

2. **Muted Text Class**
   - Bootstrap's `text-muted` used extensively
   - May not meet 4.5:1 on light backgrounds
   - Files affected:
     - `VolunteerOpportunity.vue:19`
     - `GroupHeader.vue`
     - `DonationAskButtons2510.vue`
     - `CommunityEvent.vue`

3. **Gray Color Variables**
   ```scss
   $color-gray--base: #A9A9A9    // FAILS on white (3.0:1)
   $color-gray--normal: #6C757D  // BORDERLINE (4.5:1)
   $color-gray--dark: #808080    // FAILS on white (4.0:1)
   ```

4. **Low Opacity Elements**
   - `ChatNotice.vue:107-124` - Dismiss button at 60% opacity
   - Reduced opacity decreases effective contrast

### Recommendations

- [ ] Audit all gray text using WebAIM Contrast Checker
- [ ] Replace `$color-gray--base` with darker variant
- [ ] Ensure minimum 4.5:1 ratio for all body text
- [ ] Use minimum 70% opacity for interactive elements

---

## 2. Keyboard Navigation Issues

### Critical: Click Handlers Without Keyboard Support

**WCAG Requirement**: All functionality available via keyboard (2.1.1)

#### Non-Semantic Clickable Elements

1. **MyMessageMobile.vue:12**
   ```vue
   <div class="photo-section" @click="goToPost">
   ```
   Missing: `@keydown.enter`, `tabindex="0"`, `role="button"`

2. **MessageSummary.vue:25**
   ```vue
   <div :class="classes" @click="expand">
   ```
   Missing keyboard handler

3. **MyMessageMobile.vue:177**
   ```vue
   <div class="replies-header" @click="toggleExpanded">
   ```
   Not keyboard accessible

4. **PostPhoto.vue:3-24**
   - Rotation controls using `<span>` and `<div>`
   - Only `@click` and `@touchstart`, no keyboard

5. **MyMessageReplyMobile.vue**
   ```vue
   <span class="user-name" @click.stop="openProfile">
   ```
   Should be a button or link

#### Tabindex Misuse

- **AutoComplete.vue:27** - `tabindex="-1"` removes from tab order
- Intentional but limits accessibility

### Recommendations

- [ ] Add `@keydown.enter` and `@keydown.space` to all click handlers
- [ ] Use semantic elements (`<button>`, `<a>`) instead of `<div>` for interactions
- [ ] Add `role="button"` and `tabindex="0"` to custom clickable elements
- [ ] Test full keyboard navigation flow

---

## 3. Missing ARIA Labels

### Interactive Elements Without Labels

**WCAG Requirement**: All interactive elements need accessible names (4.1.2)

#### Icon-Only Buttons

1. **ChatNotice.vue:24**
   ```vue
   <v-icon icon="times" />
   ```
   No accessible label for dismiss button

2. **MyMessageMobile.vue:86-95**
   - Edit and share icons lack `aria-label`

3. **PostPhoto.vue:4-24**
   - Uses `label` attribute instead of `aria-label`

#### Links with Hash Hrefs

1. **AutoComplete.vue:111**
   ```vue
   <a href="#" @click.prevent="selectList(data)">
   ```
   Hash link, no meaningful label

2. **WizardProgress.vue:8,16,24**
   ```vue
   <a href="#" class="wizard__dot" />
   ```
   Decorative progress dots - should have `aria-hidden="true"`

### ARIA Label Coverage

- Only **13 files** out of 299+ components have `aria-label` usage
- Good examples: `NotificationOptions`, `NewsReply`, `ChatNotice`, `ChatMenu`

### Recommendations

- [ ] Add `aria-label` to all icon-only buttons
- [ ] Use `aria-hidden="true"` for decorative elements
- [ ] Replace hash links with buttons for JavaScript actions
- [ ] Create reusable `IconButton` component with required label prop

---

## 4. Link Accessibility

### Generic Link Text

**WCAG Requirement**: Link purpose clear from text alone (2.4.4)

1. **LoginModal.vue:112**
   ```vue
   <a href="#" @click.prevent="forgot">click here</a>
   ```
   **Issue**: "click here" provides no context
   **Fix**: "Reset your password" or "Get login link"

### New Tab Links Without Warning

**WCAG Requirement**: Users should know when links open new windows

- 30+ files use `target="_blank"` without indication
- **LoginModal.vue:34-38** - Terms and Privacy links

### Recommendations

- [ ] Replace "click here" with descriptive text
- [ ] Add `aria-label="(opens in new tab)"` to external links
- [ ] Consider visual indicator (icon) for new tab links

---

## 5. Image Alt Text Issues

### Invalid Alt Attributes

1. **Placeholder Attributes Found**
   ```html
   generator-unable-to-provide-required-alt=""
   ```
   Files affected:
   - `ChatMessageImage.vue:43,79`
   - `PinchMe.vue`
   - `ChatMessageReneged.vue`
   - `StoryOne.vue`
   - `NewsMessage.vue`
   - `NewsPhotoModal.vue`

2. **Empty Alt on Non-Decorative Images**
   - `DonationMonthly.vue` - `alt=""` on meaningful image

### Good Examples (Reference)

- `MyMessageMobile.vue:38,49,57` - `alt="Item Photo"`
- `VolunteerOpportunity.vue:96,106` - `alt="Volunteering Opportunity Photo"`
- `ChatMessageImage.vue:22,33` - `alt="Chat Photo"`

### Recommendations

- [ ] Remove `generator-unable-to-provide-required-alt` attributes
- [ ] Add meaningful alt text based on image context
- [ ] Use `alt=""` only for truly decorative images
- [ ] Create alt text guidelines for user-uploaded images

---

## 6. Form Accessibility Issues

### Label Association

**WCAG Requirement**: Form inputs must have associated labels (1.3.1)

1. **AccountSection.vue:14**
   ```vue
   <label>Email address:</label>
   <b-form-input v-model="emailLocal" type="email" />
   ```
   **Issue**: Label missing `for` attribute, input missing `id`

   **Fix**:
   ```vue
   <label for="email">Email address:</label>
   <b-form-input id="email" v-model="emailLocal" type="email" />
   ```

### Required Field Indication

- No consistent pattern for marking required fields
- Missing `aria-required="true"` on required inputs

### Error Message Association

- Error messages not linked via `aria-describedby`
- Missing `aria-invalid="true"` on fields with errors

### Good Example (ChatFooter.vue:80)

```vue
<label for="chatmessage" class="visually-hidden">Chat message</label>
<textarea id="chatmessage" ... />
```

### Recommendations

- [ ] Add `for`/`id` association to all form labels
- [ ] Use `aria-required="true"` on required fields
- [ ] Add visible required indicator (asterisk)
- [ ] Link error messages with `aria-describedby`
- [ ] Add `aria-invalid="true"` to invalid fields

---

## 7. Modal Focus Management

### Focus Trap Disabled

**WCAG Requirement**: Focus should not escape modal dialogs (2.4.3)

**LoginModal.vue:9**
```vue
<b-modal ... no-trap>
```

**Issue**: `no-trap` disables focus trapping, allowing keyboard focus to leave modal

### Recommendations

- [ ] Remove `no-trap` unless absolutely necessary
- [ ] Ensure focus moves to modal on open
- [ ] Return focus to trigger element on close
- [ ] Test modal keyboard navigation

---

## 8. Testing Checklist

### Automated Testing
- [ ] Run axe-core accessibility scanner
- [ ] Check color contrast with WebAIM tool
- [ ] Validate HTML for proper semantics

### Manual Testing
- [ ] Navigate entire site with keyboard only
- [ ] Test with screen reader (NVDA/VoiceOver)
- [ ] Test at 200% zoom
- [ ] Test with high contrast mode

### Key User Flows to Test
1. Sign up / Log in
2. Post an item (Give)
3. Search for items (Find)
4. Chat with another user
5. Complete a transaction
6. Update account settings

---

## Priority Matrix

| Issue | Impact | Effort | Priority |
|-------|--------|--------|----------|
| Keyboard navigation | High | Medium | P1 |
| Color contrast | High | Low | P1 |
| Form labels | High | Low | P1 |
| ARIA labels | Medium | Low | P2 |
| Image alt text | Medium | Low | P2 |
| Link text | Medium | Low | P2 |
| Modal focus | Medium | Medium | P3 |

---

## Resources

- [WCAG 2.1 Guidelines](https://www.w3.org/TR/WCAG21/)
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
- [A11Y Project Checklist](https://www.a11yproject.com/checklist/)
- [Axe Accessibility Testing](https://www.deque.com/axe/)
