# Spam and Content Moderation Rethink

## Status: Future Planning (Post-Migration)

**IMPORTANT**: This document covers **FUTURE** improvements to spam detection and content moderation. These improvements will be implemented **AFTER** the email migration is complete and stable.

**For the initial email migration**, we are porting the current spam detection approach unchanged. See the "Current Spam Detection Module" section in the email migration plan.

This document covers:
- LLM-based intent detection (future)
- Spam signatures with fuzzy hashing (future)
- Unified content moderation workflow (future)
- ModTools interface improvements (future)

## Related Documents

- [Incoming Email Migration to Laravel](incoming-email-migration-to-laravel.md) - Current migration (ports existing spam detection unchanged)
- This document focuses on **future** improvements to detection, reporting, and moderation workflow

---

## Executive Summary

### Problems to Solve

1. **Volunteer confusion**: Multiple spam-related buttons with unclear distinctions (spam vs scam vs rule breach)
2. **Ineffective blocking**: Email-based blocking is pointless with disposable addresses
3. **Keyword limitations**: Spammers easily evade keyword filters
4. **Inconsistent UI**: Different interfaces for pending messages, approved messages, and chat
5. **Missing explanations**: Moderators don't know why something was flagged
6. **Spam team workflow**: Current tools may not match new detection approach

### Key Decisions Still Needed

- [ ] Should we revise spam detection approach (signatures vs keywords)?
- [ ] How does this affect the spam team workflow?
- [ ] What changes are needed to the volunteer-facing ModTools interface?
- [ ] How do we handle the transition from current to new system?

---

## Current State Analysis

### Where Problematic Content Appears

| Context | Current Detection | Current Reporting |
|---------|------------------|-------------------|
| **Pending messages** | Spam keywords, worry words, Rspamd | Approve/Reject/Spam buttons |
| **Approved messages** | None (already approved) | Report dropdown, user reports |
| **Chat messages** | Limited/none | Report button (user-initiated) |

### Current Confusion Points

**For volunteers**:
- "Report User as Spammer" vs "Report as Spam" vs "Report as Scam"
- When to use each option?
- What's the difference in outcome?

**For spam team**:
- Reports come in with varying levels of detail
- Hard to identify patterns across reports
- Disposable email addresses make sender-blocking ineffective

### Current Database Tables

- `spam_keywords` - 311 keywords for spam detection
- `worrywords` - 272 keywords for regulatory/safety compliance
- Various spam reporting tables

---

## Proposed Approach

### Unified Content Moderation

**Principle**: Volunteers shouldn't need to understand spam vs scam vs rule breach. They just need to say "this is a problem."

#### Problem Categories (System-Determined)

| Type | Meaning | Examples |
|------|---------|----------|
| **Selling** | Trying to sell or ask for money | "Just £20 for collection" |
| **Scam** | Fraudulent / phishing | Advance fee fraud, romance scams |
| **Rule breach** | Violates Freegle rules | Regulated items, wrong category |
| **Inappropriate** | Harassment, profanity | Abusive messages |
| **Spam** | Bulk unsolicited | Commercial spam, viagra |
| **Other** | Doesn't fit categories | Needs human review |

#### Moderator Input: Free-Form with LLM Categorisation

Since we're using an LLM for content analysis, volunteers can describe problems in their own words. The LLM categorises their input - no dropdown required.

**Benefits**:
- More empowering for volunteers (express themselves naturally)
- Captures nuance that dropdowns miss
- Uses infrastructure we're already building
- Volunteers feel heard, not constrained

**Interface**:

```
┌─────────────────────────────────────────────────────────────┐
│ Content removed.                                            │
│                                                             │
│ What was wrong with this? (optional)                        │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ They were asking for £20 to cover their costs, which   │ │
│ │ isn't allowed on Freegle                               │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ [Submit]  [Skip]                                            │
└─────────────────────────────────────────────────────────────┘
```

**Behind the scenes**:

The LLM processes the volunteer's description:

```json
{
  "volunteer_input": "They were asking for £20 to cover their costs, which isn't allowed on Freegle",
  "llm_analysis": {
    "category": "selling",
    "confidence": 0.95,
    "key_points": ["payment request", "rule awareness"],
    "sentiment": "matter-of-fact"
  }
}
```

This gives us:
- Structured data for spam signatures and learning
- The volunteer's actual words for spam team review
- Rich context that dropdowns can't capture

**Examples of what volunteers might write**:

| Volunteer Input | LLM Category |
|----------------|--------------|
| "Asking for money" | selling |
| "This looks like one of those scam messages we keep seeing" | scam |
| "Shouldn't be giving away medicines" | rule_breach |
| "Being rude and aggressive" | inappropriate |
| "Random junk advertising viagra" | spam |
| "Not sure but something feels off" | other + flag for review |

**Fallback for volunteers who don't want to type**:

Some volunteers prefer quick actions. Offer an optional "quick pick" that expands:

```
┌─────────────────────────────────────────────────────────────┐
│ Content removed.                                            │
│                                                             │
│ What was wrong? [Quick pick ▼] or type below:               │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ [Submit]  [Skip]                                            │
└─────────────────────────────────────────────────────────────┘
```

Quick pick options (just shortcuts for common phrases):
- "Asking for money" → fills text box
- "Looks like a scam" → fills text box
- "Against the rules" → fills text box
- "Spam/junk" → fills text box

The quick picks just populate the text box - the LLM still processes it the same way.

---

## Detection Approaches

### Option A: Enhanced Keyword Detection (Incremental)

Keep current keyword approach but improve it:

**Pros**:
- Familiar to spam team
- Predictable behaviour
- Easy to update
- No ML infrastructure needed

**Cons**:
- Easily evaded
- Requires constant maintenance
- False positives on legitimate content
- Can't detect novel spam patterns

### Option B: Spam Signatures with Fuzzy Hashing (Proposed)

Detect spam by content similarity rather than keywords:

**How it works**:
1. When spam is reported, create a "signature" (fuzzy hash + semantic features)
2. New content is compared against known signatures
3. Similar content is automatically flagged/blocked
4. System learns from moderator decisions

**Pros**:
- Catches variations of known spam
- Harder to evade (changing a few words doesn't help)
- Learns from reports automatically
- Reduces moderator workload over time

**Cons**:
- New infrastructure needed
- Learning curve for spam team
- Need to bootstrap with initial signatures
- Potential for novel spam to slip through initially

### Option C: LLM-Based Intent Detection (Proposed)

Use local LLM to understand message intent:

**Detects**:
- Payment requests ("just cover my costs")
- Off-platform requests ("email me directly")
- Selling attempts ("make me an offer")
- Suspicious patterns (urgency, external contacts)

**Pros**:
- Understands context and intent
- Catches novel expressions
- Reduces false positives (understands "Cock Lane" is an address)
- Provides human-readable explanations

**Cons**:
- Requires local LLM infrastructure
- Processing time (~1-2s per message)
- May need fine-tuning for Freegle context
- "Black box" nature harder to debug

### Recommended: Hybrid Approach

Combine all three in layers:

```
Incoming Content
      │
      ▼
┌─────────────────┐
│ 1. Rspamd       │ ◄── Generic spam, phishing (email only)
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ 2. Signatures   │ ◄── Known spam patterns (fuzzy match)
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ 3. Regulatory   │ ◄── UK regulated substances (exact match)
│    Keywords     │     ~170 keywords, legal requirement
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ 4. LLM Intent   │ ◄── Money, selling, off-topic detection
│    Detection    │     Context-aware, handles slang
└────────┬────────┘
         │
         ▼
    Route/Flag
```

---

## Spam Signatures System

### What is a Spam Signature?

A fingerprint of a spam *type* that matches similar messages regardless of surface wording variation.

```json
{
  "id": "sig_courier_fee_2024",
  "type": "scam",
  "description": "Courier fee scam - asks recipient to pay shipping costs",
  "fuzzy_hash": "a3f2b1c4d5e6f7...",
  "semantic_features": {
    "mentions_shipping": true,
    "asks_for_payment": true,
    "provides_external_contact": true,
    "uses_urgency": true
  },
  "template": "Hello {greeting}, your item is ready. Please pay {amount} courier fee to {contact}...",
  "confidence_threshold": 0.85,
  "auto_action": "block",
  "match_count": 247,
  "false_positive_count": 2,
  "created_by": "spam_team",
  "created_at": "2024-12-15"
}
```

### Signature Creation Workflow

**Step 1**: Multiple similar reports come in
- System detects cluster using fuzzy hashing
- Groups similar content together

**Step 2**: Spam team reviews cluster
- Sees sample messages
- Confirms pattern is spam
- Approves signature creation

**Step 3**: System creates signature
- Extracts template with variable parts
- Sets confidence threshold
- Chooses auto-action (flag/hide/block)

**Step 4**: Ongoing matching
- New content compared against signatures
- Matches are auto-actioned or flagged
- False positives feed back into refinement

### Spam Team Interface

```
┌─────────────────────────────────────────────────────────────┐
│ New Pattern Detected                                        │
│ 15 similar reports in last 48 hours                        │
├─────────────────────────────────────────────────────────────┤
│ Sample messages (click to expand):                          │
│ • "Hello dear, I am very interested in your sofa..."       │
│ • "Hello friend, I am interested in your table..."         │
│ • "Dear sir, I would like your washing machine..."         │
├─────────────────────────────────────────────────────────────┤
│ Detected patterns:                                          │
│ ✓ Generic greeting ("dear", "friend")                      │
│ ✓ Asks to contact off-platform (external email)            │
│ ✓ Generic interest (not specific to item)                  │
│ ✓ New accounts (avg age: 3 hours)                          │
├─────────────────────────────────────────────────────────────┤
│ Extracted template:                                         │
│ "Hello {greeting}, I am interested in your {item}.         │
│ Please contact me at {external_email}"                      │
├─────────────────────────────────────────────────────────────┤
│ Recommended action: Block (confidence: 92%)                 │
│                                                             │
│ [Create Signature] [Not Spam] [Need More Examples]          │
└─────────────────────────────────────────────────────────────┘
```

---

## LLM-Based Intent Detection

### Why Intent Over Keywords?

| Keyword Approach | Intent Approach |
|------------------|-----------------|
| Detect "£", "quid", "tenner" | Detect "requesting payment for item" |
| Detect "PayPal", "bank transfer" | Detect "arranging financial transaction" |
| Whitelist "money box", "Pound Lane" | Understands these are objects/places |
| Misses "just cover my costs" | Catches any payment request |

### Intent Categories

| Intent | Description | Action |
|--------|-------------|--------|
| `payment_request` | Asking for money for item | Flag for review |
| `payment_offer` | Offering to pay (suspicious) | Flag for review |
| `off_platform` | Moving conversation elsewhere | Flag for review |
| `selling` | Treating as marketplace | Flag for review |
| `hostile` | Abusive language | Flag + warn user |
| `off_topic` | Not about freecycling | Flag for review |

### Explanation Requirement

Every flagged message must include clear explanation:

```json
{
  "intent": "payment_request",
  "confidence": "high",
  "explanation": "Message appears to ask for payment. The phrase 'just £10 to cover costs' suggests the user wants money for the item.",
  "excerpt": "...happy to let it go for just £10 to cover my costs...",
  "source": "llm_classifier"
}
```

### Infrastructure Requirements

**Model options** (local, no external API):
- Phi-3-mini (3.8B params) - Best quality, ~1-2s inference
- Qwen2.5-3B - Good balance
- TinyLlama-1.1B - Fastest, lower quality

**Given measured volume** (~1,350 messages/day needing analysis):
- Average ~1 message/minute
- Peak ~5 messages/minute
- 1-2 second processing time is acceptable
- Can use larger, more capable model

---

## Unified ModTools Interface

### Current Problems

- Pending queue: Approve/Reject/Spam/Hold buttons
- Messages: "Actions" dropdown with many options
- Chat: Separate reporting mechanism
- Different terminology everywhere

### Proposed: Single Review Queue

All flagged/reported content in one place:

```
┌─────────────────────────────────────────────────────────────┐
│ Content Review                                 [Filter ▼]   │
├─────────────────────────────────────────────────────────────┤
│ ⚠️ PENDING - Testerton Freegle                              │
│ "Free sofa - just £20 for collection..."                   │
│ Flagged: Payment request • Confidence: High                │
│                                    [View] [Approve] [Remove]│
├─────────────────────────────────────────────────────────────┤
│ 🚨 CHAT - Reported by user                                  │
│ "Hello dear friend, I am interested..."                    │
│ Reported by: John S. • "Looks like a scam"                 │
│                                    [View] [Keep] [Remove]   │
├─────────────────────────────────────────────────────────────┤
│ ⚠️ APPROVED - System flagged                                │
│ "Offering prescription medication..."                       │
│ Flagged: Regulated substance • Confidence: Certain         │
│                                    [View] [Keep] [Remove]   │
└─────────────────────────────────────────────────────────────┘
```

### Terminology Standardisation

| Old Terms | New Term |
|-----------|----------|
| Spam, Scam, Report | **Report Problem** |
| Reject, Delete, Remove | **Remove** |
| Approve, Keep, Allow | **Approve** / **Keep** |
| Spammer, Block User | **Suspend User** |

---

## Chat Message Considerations

Chat has additional complexity:

1. **Context matters**: "I'll pay you" after "Can you deliver?" is different from unsolicited
2. **Both parties can report**: Either participant can flag
3. **Conversation history**: May need full thread for context
4. **Privacy**: More sensitive than public posts

### Chat Report Flow

For users (free-form, consistent with moderator interface):
```
┌─────────────────────────────────────────────────────────────┐
│ Report Chat Message                                         │
├─────────────────────────────────────────────────────────────┤
│ You're reporting this message from Jane Smith:              │
│ "Just send me £10 via PayPal and I'll post it"             │
│                                                             │
│ What's the problem?                                         │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ They're asking me to pay for something that should be  │ │
│ │ free                                                    │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ □ Also block this user from contacting me                   │
│                                                             │
│ [Cancel]                              [Report]              │
└─────────────────────────────────────────────────────────────┘
```

The LLM processes the user's description just like moderator input, categorising it for the review queue while preserving their actual words.

For moderators reviewing chat reports:
- Show reported message in context (surrounding messages)
- Show reporter's reason and any notes
- Same Approve/Remove actions as other content

---

## Database Schema

### Content Reports (Unified)

```sql
CREATE TABLE content_reports (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,

    -- What was reported (exactly one set)
    message_id BIGINT NULL,
    chat_message_id BIGINT NULL,

    -- Reporter
    reported_by BIGINT NOT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    source ENUM('user', 'moderator', 'system') NOT NULL,

    -- Reporter's input (optional)
    reporter_category ENUM('selling', 'scam', 'rule_breach',
                           'inappropriate', 'spam', 'other') NULL,
    reporter_notes TEXT NULL,

    -- System detection (if source = 'system')
    detection_reason VARCHAR(50) NULL,
    detection_explanation TEXT NULL,
    detection_confidence DECIMAL(3,2) NULL,

    -- Resolution
    status ENUM('pending', 'removed', 'kept', 'escalated') DEFAULT 'pending',
    resolved_by BIGINT NULL,
    resolved_at TIMESTAMP NULL,
    resolution_category ENUM('selling', 'scam', 'rule_breach',
                             'inappropriate', 'spam', 'other', 'false_positive') NULL,
    resolution_notes TEXT NULL,

    -- Signature learning
    matched_signature_id BIGINT NULL,
    contributed_to_signature_id BIGINT NULL,

    INDEX idx_status (status),
    INDEX idx_reported_at (reported_at)
);
```

### Spam Signatures

```sql
CREATE TABLE spam_signatures (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,

    -- Identification
    fuzzy_hash VARCHAR(64),
    semantic_features JSON,
    template TEXT,

    -- Classification
    spam_type ENUM('selling', 'scam', 'phishing', 'romance',
                   'advance_fee', 'off_platform', 'bulk', 'other'),
    description TEXT,

    -- Behaviour
    confidence_threshold DECIMAL(3,2) DEFAULT 0.85,
    auto_action ENUM('none', 'flag', 'hide', 'block') DEFAULT 'flag',

    -- Statistics
    match_count INT DEFAULT 0,
    false_positive_count INT DEFAULT 0,
    last_matched_at TIMESTAMP NULL,

    -- Audit
    created_by BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('proposed', 'active', 'disabled') DEFAULT 'proposed',

    INDEX idx_status (status),
    INDEX idx_fuzzy_hash (fuzzy_hash)
);
```

---

## Transition Considerations

### For Spam Team

- New signature-based workflow vs keyword maintenance
- Training on new interface
- Bootstrap period with manual signature creation
- Gradual automation as system learns

### For Volunteers

- Simpler interface (fewer buttons)
- Clear explanations for flagged content
- Optional categorisation (not required)
- Consistent experience across contexts

### Technical Migration

- Run new detection in parallel initially
- Compare results with existing system
- Gradual rollout by group or percentage
- Fallback to old system if issues

---

## Open Questions

1. **Signature vs Keywords**: Do we move fully to signatures, or keep keywords as backup?

2. **Spam Team Workflow**: How does the spam team want to work with signatures?

3. **Auto-blocking Threshold**: At what confidence level should we auto-block without review?

4. **Chat Analysis**: Should chat messages go through the same analysis as posts?

5. **Training Data**: How do we bootstrap the LLM classifier? Use Piler archive?

6. **Transition Timeline**: How do we phase this in without disrupting current moderation?

---

## Next Steps

1. **Stakeholder Review**: Discuss with spam team about proposed workflow changes
2. **Technical Spike**: Test fuzzy hashing approach with real spam samples
3. **LLM Evaluation**: Benchmark local LLM models for intent detection accuracy
4. **UI Mockups**: Create detailed mockups for volunteer and spam team interfaces
5. **Parallel Testing**: Set up infrastructure to compare new vs old detection
