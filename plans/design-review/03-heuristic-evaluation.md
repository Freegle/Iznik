# Freegle Heuristic Evaluation (Nielsen's 10 Usability Heuristics)

## Overview

This evaluation applies Jakob Nielsen's 10 usability heuristics to the Freegle web application. Each heuristic is assessed with specific examples, severity ratings, and recommendations.

**Severity Scale:**
- 0 = Not a problem
- 1 = Cosmetic only
- 2 = Minor usability problem
- 3 = Major usability problem
- 4 = Usability catastrophe

---

## 1. Visibility of System Status

> "The design should always keep users informed about what is going on, through appropriate feedback within a reasonable amount of time."

### Assessment: Good with Issues (Severity: 2)

#### Strengths
- **Loading states**: Site uses `client-only` wrappers with fallback content during hydration
- **Chat search**: Shows "Searching..." pulsating text during search operations (`chats/[[id]].vue:76`)
- **Wizard progress**: Clear step indicators in posting flow (`WizardProgress.vue`)
- **Message validation**: Real-time feedback on form validity (`give/index.vue:92-94`)

#### Issues Found

1. **Inconsistent loading indicators** (Severity: 2)
   - Four different loading implementations (GIF, component, JumpingDots, spinner)
   - Users can't build a consistent mental model

2. **No upload progress** (Severity: 2)
   - Photo uploads don't show progress percentage
   - Long uploads on slow connections leave users uncertain

3. **Missing save confirmations** (Severity: 2)
   - Settings changes don't always confirm they've saved
   - Users may be unsure if changes took effect

#### Recommendations
- [ ] Consolidate to single loading component with consistent animation
- [ ] Add upload progress indicator to photo uploader
- [ ] Show toast/notification on successful saves

---

## 2. Match Between System and Real World

> "The design should speak the users' language. Use words, phrases, and concepts familiar to the user, rather than internal jargon."

### Assessment: Excellent (Severity: 1)

#### Strengths
- **Clear terminology**: "Give" and "Find" are intuitive actions
- **Friendly messaging**: Taglines like "Share the love. Love the share." and "Don't throw it away, give it away!"
- **Real-world metaphors**: "Like online dating for stuff" - relatable comparison
- **Plain language**: Help content uses conversational tone

#### Issues Found

1. **"Freegling" terminology** (Severity: 1)
   - "See what's being freegled near you" - coined term may confuse new users
   - Not immediately clear what "freegled" means

2. **Technical terms occasionally appear** (Severity: 1)
   - "simplemail" in settings
   - Some error messages may contain technical details

#### Recommendations
- [ ] Consider "shared for free" instead of "freegled" for first-time visitors
- [ ] Audit all user-facing text for jargon

---

## 3. User Control and Freedom

> "Users often perform actions by mistake. They need a clearly marked 'emergency exit' to leave the unwanted action without having to go through an extended process."

### Assessment: Good with Issues (Severity: 2)

#### Strengths
- **Clear form action** in posting flow: "Clear form" button visible (`give/index.vue:38-41`)
- **Delete last item** option when multiple items added
- **Hide/unhide chats**: Users can reverse hiding chats
- **Cancel buttons** in modals consistently present

#### Issues Found

1. **No undo for sent messages** (Severity: 2)
   - Chat messages cannot be recalled/deleted after sending
   - Common feature in modern messaging apps

2. **Post deletion process unclear** (Severity: 2)
   - How to withdraw/delete a posted item isn't immediately obvious
   - Should be accessible from message view

3. **One-way actions lack confirmation** (Severity: 3)
   - Some destructive actions proceed without confirmation
   - "Hide all chats" appropriately has confirmation modal

4. **Browser back button issues** (Severity: 2)
   - Chat navigation uses `router.replace()` which doesn't add to history
   - Can be confusing when Back doesn't go where expected

#### Recommendations
- [ ] Add edit/delete capability for recent chat messages (time-limited)
- [ ] Make post withdrawal more discoverable
- [ ] Add confirmation dialogs to all destructive actions
- [ ] Consider breadcrumb navigation for multi-step flows

---

## 4. Consistency and Standards

> "Users should not have to wonder whether different words, situations, or actions mean the same thing. Follow platform and industry conventions."

### Assessment: Needs Improvement (Severity: 3)

#### Strengths
- **Consistent header/footer** across pages
- **Consistent button primary color** (green for primary actions)
- **Standard Bootstrap components** used throughout

#### Issues Found

1. **Modal footer button inconsistency** (Severity: 2)
   - `ConfirmModal.vue` uses `variant="white"` for Cancel
   - Other modals use `variant="secondary"` for Cancel
   - Button order varies (Cancel|Primary vs Primary|Cancel)

2. **Icon sizing inconsistency** (Severity: 2)
   - Mix of `fa-2x` class and `size="2x"` prop
   - Creates visual inconsistency

3. **Form validation patterns vary** (Severity: 2)
   - Some forms show inline errors
   - Others show toast notifications
   - Some just disable submit button

4. **Color naming convention** (Severity: 1)
   - Mix of British "colour" and American "color" in code
   - `$colour-success` vs `$color-green--dark`

5. **Loading indicator variance** (Severity: 3)
   - Four different loading patterns confuse users
   - Should be one recognizable loading state

6. **Desktop vs Mobile layout differences** (Severity: 2)
   - Different information architecture mobile vs desktop
   - Mobile redirects to different route (`/give/mobile/photos`)

#### Recommendations
- [ ] Create modal template with standardized footer
- [ ] Document and enforce icon sizing via props only
- [ ] Standardize form validation to inline errors
- [ ] Unify color variable naming (pick one spelling)
- [ ] Single loading component across site

---

## 5. Error Prevention

> "Good error messages are important, but the best designs carefully prevent problems from occurring in the first place."

### Assessment: Good (Severity: 2)

#### Strengths
- **Form validation before submit**: "Please add the item name, and a description or photo" (`give/index.vue:92-94`)
- **Disabled states**: Submit buttons disabled until form is valid
- **Confirmation modals**: `ConfirmModal.vue` for destructive actions
- **Type restrictions**: Input types enforce correct data (email, number)

#### Issues Found

1. **No character limits shown** (Severity: 2)
   - Description textarea has no visible character limit
   - Users don't know if they're approaching a limit

2. **Missing inline validation** (Severity: 2)
   - Email format not validated until submission
   - Should show validation as user types

3. **Location input ambiguity** (Severity: 2)
   - Location autocomplete could accept invalid locations
   - No visual confirmation of selected location accuracy

4. **Photo upload constraints unclear** (Severity: 1)
   - Max file size, dimensions not communicated upfront
   - Error only shown after failed upload

#### Recommendations
- [ ] Add character counters to text inputs with limits
- [ ] Implement real-time validation with debounce
- [ ] Show selected location on map for confirmation
- [ ] Display upload constraints before upload begins

---

## 6. Recognition Rather Than Recall

> "Minimize the user's memory load by making elements, actions, and options visible."

### Assessment: Good (Severity: 2)

#### Strengths
- **Visual message cards**: Item photos prominently displayed
- **Recent chats visible**: Chat list always accessible
- **Search functionality**: Chat search helps find conversations
- **Last homepage remembered**: Returns users to their preferred view

#### Issues Found

1. **No search on main browse** (Severity: 2)
   - Finding specific items requires remembering keywords
   - Search/filter could be more prominent

2. **Hidden features** (Severity: 2)
   - "Show older chats" requires knowing it exists
   - Many features only discoverable through exploration

3. **Draft messages not persistent** (Severity: 2)
   - If user navigates away from compose, work may be lost
   - Should auto-save drafts

4. **Group membership not visible** (Severity: 1)
   - Users may not remember which groups they've joined
   - Could show membership status more prominently

#### Recommendations
- [ ] Add prominent search/filter to browse pages
- [ ] Surface hidden features via tooltips or onboarding
- [ ] Implement draft auto-save
- [ ] Show group membership badges

---

## 7. Flexibility and Efficiency of Use

> "Shortcuts—hidden from novice users—may speed up the interaction for the expert user."

### Assessment: Adequate (Severity: 2)

#### Strengths
- **Quick post flow**: Streamlined path to post items
- **Keyboard accessible**: Forms navigable via Tab
- **Bulk actions**: "Mark all read" in chat list
- **Hide all chats**: Power user bulk action

#### Issues Found

1. **No keyboard shortcuts** (Severity: 2)
   - No documented keyboard shortcuts for common actions
   - Power users can't speed up workflow

2. **No templates/presets** (Severity: 2)
   - Frequent posters can't save common descriptions
   - Each post starts from scratch

3. **Limited bulk operations** (Severity: 1)
   - Can't bulk-delete or bulk-archive messages
   - Managing many posts is tedious

4. **No quick reply** (Severity: 2)
   - Must navigate to full chat to respond
   - Quick inline reply would be faster

#### Recommendations
- [ ] Add keyboard shortcuts (n=new post, c=chats, etc.)
- [ ] Allow saving description templates
- [ ] Add bulk selection for message management
- [ ] Consider inline quick reply in notifications

---

## 8. Aesthetic and Minimalist Design

> "Interfaces should not contain information that is irrelevant or rarely needed."

### Assessment: Good with Issues (Severity: 2)

#### Strengths
- **Clean landing page**: Clear CTAs, minimal clutter
- **Focused posting flow**: One task at a time
- **Progressive disclosure**: Advanced options hidden initially
- **Whitespace usage**: Good breathing room in desktop layout

#### Issues Found

1. **Mobile landing dense** (Severity: 2)
   - Mobile shows photos + CTAs + location + app badges all at once
   - Could be simplified further

2. **Chat page complexity** (Severity: 2)
   - Desktop shows chat list + chat pane + sidebar
   - Lots of competing elements

3. **Ad integration** (Severity: 2)
   - Ads add visual noise and compete for attention
   - Necessary for sustainability but impacts UX

4. **Settings page overwhelming** (Severity: 2)
   - Many options presented at once
   - Could benefit from categorization/tabs

5. **Notice messages proliferation** (Severity: 1)
   - Multiple notice variants (info, warning, danger, primary)
   - Sometimes stack up creating visual clutter

#### Recommendations
- [ ] Simplify mobile landing to essential elements
- [ ] Consider tabbed interface for settings
- [ ] Limit simultaneous notices to 1-2
- [ ] Review ad placement for minimal disruption

---

## 9. Help Users Recognize, Diagnose, and Recover from Errors

> "Error messages should be expressed in plain language, precisely indicate the problem, and constructively suggest a solution."

### Assessment: Adequate (Severity: 2)

#### Strengths
- **NoticeMessage component**: Consistent error display styling
- **Clear variant colors**: Danger (red), warning (yellow), info (blue)
- **Inline validation messages**: Form errors shown near inputs
- **Account restoration notice**: Clear message for deleted accounts

#### Issues Found

1. **Generic error messages** (Severity: 3)
   - Some API errors shown as-is without user-friendly translation
   - Technical errors can confuse users

2. **Missing recovery paths** (Severity: 2)
   - Errors don't always suggest what to do next
   - "Contact support" links not always present

3. **Error message accessibility** (Severity: 2)
   - Errors not linked via `aria-describedby`
   - Screen readers may miss error context

4. **SomethingWentWrong component** (Severity: 1)
   - Generic fallback exists but could be more helpful
   - Should offer specific recovery actions

#### Recommendations
- [ ] Create error message mapping for common API errors
- [ ] Always include recovery action in error messages
- [ ] Link errors to form fields with `aria-describedby`
- [ ] Add "Report this issue" link to error states

---

## 10. Help and Documentation

> "It's best if the system doesn't need any additional explanation. However, it may be necessary to provide documentation to help users understand how to complete their tasks."

### Assessment: Good (Severity: 1)

#### Strengths
- **Dedicated help page**: `/help` route exists
- **HelpChatFlow component**: Guided help experience
- **Contextual guidance**: Posting flow includes inline help text
- **Placeholder examples**: "e.g. colour, condition, size..."
- **Terms and Privacy**: Legal docs easily accessible

#### Issues Found

1. **No contextual help tooltips** (Severity: 2)
   - Forms lack "?" icons for field explanations
   - Users must navigate away to find help

2. **No FAQ section visible** (Severity: 1)
   - Common questions not prominently addressed
   - Could reduce support burden

3. **No onboarding for new users** (Severity: 2)
   - First-time users get no guided tour
   - Features must be discovered organically

4. **Help search limited** (Severity: 1)
   - Can't search help content directly
   - Must browse through chat flow

#### Recommendations
- [ ] Add contextual help icons with tooltips
- [ ] Create searchable FAQ section
- [ ] Implement first-use onboarding tour
- [ ] Add inline help search

---

## Summary Table

| Heuristic | Rating | Severity | Priority |
|-----------|--------|----------|----------|
| 1. Visibility of system status | Good | 2 | Medium |
| 2. Match system/real world | Excellent | 1 | Low |
| 3. User control and freedom | Good | 2 | Medium |
| 4. Consistency and standards | Needs work | 3 | High |
| 5. Error prevention | Good | 2 | Medium |
| 6. Recognition vs recall | Good | 2 | Medium |
| 7. Flexibility/efficiency | Adequate | 2 | Medium |
| 8. Aesthetic/minimalist | Good | 2 | Medium |
| 9. Error recovery | Adequate | 2 | High |
| 10. Help and documentation | Good | 1 | Low |

### Top Priority Issues

1. **Consistency** (H4): Standardize modals, loading states, icons
2. **Error messages** (H9): User-friendly errors with recovery paths
3. **System status** (H1): Unified loading indicator
4. **User control** (H3): Undo/delete for messages
5. **Efficiency** (H7): Keyboard shortcuts for power users
