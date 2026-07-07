# Freegle Cognitive Load Analysis

## Overview

Cognitive load refers to the amount of mental effort required to use an interface. This analysis examines Freegle through the lens of cognitive load theory, identifying areas where users may experience unnecessary mental strain.

### Types of Cognitive Load

1. **Intrinsic Load**: Inherent complexity of the task itself
2. **Extraneous Load**: Unnecessary complexity from poor design
3. **Germane Load**: Productive mental effort for learning

The goal is to **minimize extraneous load** while maintaining intrinsic load appropriate to the task.

---

## User Flow Analysis

### Flow 1: New User Posting an Item (Give)

**Steps analyzed:**
1. Landing page → Click "Give"
2. Add photos
3. Enter item details (type, name, quantity, description)
4. Enter location
5. Complete profile (if needed)
6. Submit post

#### Cognitive Load Assessment

| Step | Intrinsic Load | Extraneous Load | Issues |
|------|----------------|-----------------|--------|
| 1. Landing | Low | Low | Clear CTAs, minimal decisions |
| 2. Photos | Medium | Low | Drag-drop intuitive |
| 3. Details | Medium | **Medium** | Multiple fields visible, quantity logic unclear |
| 4. Location | Low | Low | Autocomplete helps |
| 5. Profile | Medium | **High** | Unexpected interruption, context switch |
| 6. Submit | Low | Low | Clear action |

**Key Issues:**
- **Profile creation mid-flow**: Asking for profile details during posting interrupts task focus
- **Quantity field visibility**: "X available" not immediately clear for single items
- **Mobile redirect**: Switching to `/give/mobile/photos` creates navigation confusion

#### Recommendations
- [ ] Collect minimal profile info upfront OR defer until after first post success
- [ ] Hide quantity field by default, reveal via "Add more" action
- [ ] Maintain consistent URL structure across device sizes

---

### Flow 2: Responding to an Item (Chat)

**Steps analyzed:**
1. Browse/search items
2. Click on item of interest
3. Send message to poster
4. Monitor chat for response
5. Arrange pickup

#### Cognitive Load Assessment

| Step | Intrinsic Load | Extraneous Load | Issues |
|------|----------------|-----------------|--------|
| 1. Browse | Medium | Low | Grid layout scannable |
| 2. View item | Low | Low | Clear item details |
| 3. Message | Low | **Medium** | Chat interface unfamiliar first time |
| 4. Monitor | Low | **Medium** | Must remember to check, notifications unclear |
| 5. Arrange | Medium | Low | Free-form conversation |

**Key Issues:**
- **Chat list complexity**: Desktop shows list + pane + sidebar simultaneously
- **Notification awareness**: Users may miss messages without email notifications
- **Contact details modal**: Unexpected prompt increases cognitive load

---

### Flow 3: Managing Active Posts (My Posts)

**Steps analyzed:**
1. Navigate to My Posts
2. View post status
3. Mark as promised/given
4. Handle multiple replies

#### Cognitive Load Assessment

| Step | Intrinsic Load | Extraneous Load | Issues |
|------|----------------|-----------------|--------|
| 1. Navigate | Low | Low | Clear menu item |
| 2. View status | Medium | **Medium** | Status indicators need learning |
| 3. Update | Medium | **Medium** | Multiple action options |
| 4. Replies | High | **High** | Managing multiple interested parties |

**Key Issues:**
- **Status mental model**: Understanding "promised", "withdrawn", "completed" requires learning
- **Reply management**: No visual prioritization of replies (e.g., by interest level, timing)
- **Decision fatigue**: Choosing among multiple respondents is inherently cognitively demanding

---

## Cognitive Load Factors

### 1. Information Density

**Landing Page (index.vue)**

| Element | Purpose | Load Added |
|---------|---------|------------|
| Hero image | Emotional appeal | Low |
| Tagline | Value proposition | Low |
| Give/Find buttons | Primary actions | Low |
| Location input | Secondary action | Medium |
| App badges | Alternative path | Medium |
| Sample items | Social proof | Medium |
| Footer | Navigation | Low |

**Assessment**: Mobile landing is **densely packed**. Users see:
- Hero with overlaid text
- Two CTAs
- Location input
- App download badges
- Sample items
- Footer

**Recommendation**: Prioritize ruthlessly. Consider:
- Hide app badges on first visit (show after engagement)
- Collapse sample items behind "See examples" link
- Increase breathing room between sections

---

### 2. Decision Complexity

**Post Creation (PostMessage.vue)**

Users must decide:
1. How many photos to add
2. Photo order (drag to reorder)
3. Item name
4. Quantity (for offers)
5. Description detail level
6. Whether to add more items

**Decisions per screen**: 6

**Hick's Law**: Response time increases with number of choices. Each additional decision adds ~50ms + cognitive overhead.

**Recommendation**: Progressive disclosure
- Start with just photo + name
- Reveal options after basics complete
- "Add details" expands to description/quantity

---

### 3. Memory Load

**Chat Page (chats/[[id]].vue)**

Users must remember:
- Which chats are about which items
- Conversation context from previous sessions
- Who they've promised items to
- Contact details exchanged

**Miller's Law**: Working memory holds 7 ± 2 items

**Issues Found:**
- No item preview in chat header
- Search requires recalling keywords
- No "promised to" indicator in chat

**Recommendation**:
- Show item thumbnail/name in chat header
- Add "This chat is about: [Item]" context
- Visual badge for "Promised" chats

---

### 4. Visual Complexity

**Desktop Chat Layout**

```
+------------------+----------------------+------------------+
|    Chat List     |     Chat Pane        |    Sidebar       |
|   (scrollable)   |    (messages)        |   (ads/jobs)     |
+------------------+----------------------+------------------+
```

**Scan path complexity**: Users must monitor three columns simultaneously.

**Issues:**
- Sidebar ads compete for attention
- Active chat highlight subtle (0.08 opacity)
- Search input blends into header

**Recommendation**:
- Stronger visual hierarchy (active chat more prominent)
- Consider hiding sidebar on chat focus
- Increase contrast on search input

---

### 5. Navigation Mental Model

**Current Structure:**

```
Home (logged in) → /browse OR /myposts OR /chitchat (remembered)
Give → /give (desktop) OR /give/mobile/photos (mobile)
Find → /find
Chats → /chats/[id]
```

**Complexity Points:**
- Different routes for same action based on device
- "Home" behavior varies by user preference
- Posting flow URL changes: /give → /give/whereami → /give/whoami

**Cognitive Map Clarity**: **Medium-Low**

Users building mental models must track:
- Where am I?
- How did I get here?
- Where can I go next?
- What's the path back?

**Recommendation**:
- Unified routes (responsive design, not separate routes)
- Breadcrumb navigation for multi-step flows
- Clear "back" affordance at each step

---

## Gestalt Principles Analysis

### Proximity

**Well Applied:**
- Form labels close to inputs (PostMessage.vue)
- Related buttons grouped (action-buttons in landing)

**Issues:**
- Chat toolbar buttons spaced evenly regardless of function
- Footer links not grouped by category

### Similarity

**Well Applied:**
- Consistent button styling (primary green, secondary blue)
- Card components have uniform appearance

**Issues:**
- Notice message variants too similar (subtle color differences)
- Loading indicators look completely different

### Continuity

**Issues:**
- Wizard progress dots not connected by line
- Mobile flow jumps between pages without visual flow

### Figure-Ground

**Well Applied:**
- Modal overlays clearly separate from background
- Active chat highlighted against list

**Issues:**
- Low-contrast text (gray on white) reduces figure clarity
- Sidebar ads can dominate figure attention

---

## Chunking Analysis

**Information Chunking in Posting Flow:**

Current state:
```
[Photos] [Type|Name|Quantity] [Description] [Next]
```

Everything visible at once = **4 distinct chunks**

**Recommended Chunking:**

```
Step 1: [Photo]
Step 2: [What is it?] → Name
Step 3: [Tell us more] → Description (optional)
Step 4: [Where?] → Location
```

Each step = **1 chunk**, reducing cognitive load at each moment.

---

## Progressive Disclosure Opportunities

| Feature | Current State | Recommended |
|---------|---------------|-------------|
| Quantity selector | Always visible | Show for multi-item only |
| "Add another item" | Always visible | Show after first item valid |
| Chat search | Always visible | Show on scroll up or via icon |
| Hide/blocked chats | Link visible | Collapse into filter menu |
| Debug info (app) | Visible on help | Behind "Advanced" toggle |
| Old chats | Link appears conditionally | Good as-is |

---

## Cognitive Walkthrough: First-Time User

### Scenario: Maria wants to give away a sofa

**Step 1: Lands on homepage**
- Mental question: "Is this the right place?"
- Answer provided: Yes, "Give stuff" button prominent
- Cognitive load: **Low** ✓

**Step 2: Clicks "Give"**
- Mental question: "What do I do first?"
- Answer provided: "First, tell us about your item" + photo uploader
- Cognitive load: **Low** ✓

**Step 3: Adds photos**
- Mental question: "How many photos? What quality?"
- Answer provided: No guidance on optimal number/quality
- Cognitive load: **Medium** - some uncertainty

**Step 4: Enters details**
- Mental question: "What fields are required?"
- Answer provided: Implicit (Next button disabled until valid)
- Cognitive load: **Medium** - trial and error to discover requirements

**Step 5: Clicks Next**
- Mental question: "What's next?"
- Answer provided: Location step
- Cognitive load: **Low** ✓

**Step 6: Enters location**
- Mental question: "Will I get results?"
- Answer provided: Autocomplete suggests valid locations
- Cognitive load: **Low** ✓

**Step 7: (If not logged in) Profile creation**
- Mental question: "Why do I need to sign up now?"
- Answer provided: Limited explanation
- Cognitive load: **High** - unexpected friction, may abandon

**Abandonment Risk**: Step 7 is highest risk for new user drop-off

---

## Recommendations Summary

### High Impact (Address First)

1. **Simplify posting flow**
   - Single-focus steps (one decision per screen)
   - Clear progress indication
   - Defer profile creation or explain upfront

2. **Reduce chat page complexity**
   - Consider collapsible sidebar
   - Stronger active state highlighting
   - Item context in chat header

3. **Unify loading states**
   - Single recognizable loading pattern
   - Reduces "what's happening?" questions

### Medium Impact

4. **Progressive disclosure in forms**
   - Hide advanced options initially
   - Reveal based on user choices

5. **Better visual hierarchy**
   - Increase contrast for actionable elements
   - De-emphasize secondary content

6. **Navigation clarity**
   - Add breadcrumbs to multi-step flows
   - Unified routes across devices

### Lower Impact (Polish)

7. **Chunking improvements**
   - Group related settings
   - Categorize help content

8. **Default smart choices**
   - Pre-select common options
   - Remember user preferences

---

## Metrics to Track

| Metric | Current Baseline | Target |
|--------|------------------|--------|
| Post completion rate | ? | +10% |
| Time to first post | ? | -20% |
| Chat response rate | ? | +15% |
| Help page visits | ? | -25% (intuitive design reduces need) |
| Feature discovery | ? | +30% (progressive disclosure helps) |

---

## Cognitive Load Score Card

| Area | Score (1-5) | Notes |
|------|-------------|-------|
| Landing page | 4/5 | Clean, focused, minor density issues on mobile |
| Posting flow | 3/5 | Good structure, profile interruption problematic |
| Chat interface | 3/5 | Functional but complex, multi-column layout |
| My Posts | 3/5 | Status management requires learning |
| Settings | 2/5 | Overwhelming, needs categorization |
| Help | 4/5 | Chat flow is innovative and accessible |

**Overall Cognitive Load Rating**: **3.2/5** (Good with room for improvement)
