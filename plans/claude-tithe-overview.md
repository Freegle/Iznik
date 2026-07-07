# Claude Tithe: AI Compute for Social Good

**Status**: DESIGN PHASE - Clear path forward identified
**Date**: 2026-01-14

---

## Vision

Charitable open-source projects are falling behind in the AI revolution. While commercial organisations accelerate with AI coding assistants, charitable projects lack resources to keep pace.

**Claude Tithe** directs AI compute toward charitable software development.

---

## The Key Insight

**RALPH is already autonomous after task selection.** When you say "work on X", Claude iterates independently until completion or blocking. If that's compliant (and it clearly is), then:

| Normal Claude | Claude Tithe |
|---------------|--------------|
| Human types task | Human clicks to approve issue |
| RALPH executes autonomously | RALPH executes autonomously |

**The human approval is the trigger.** Everything after that is standard Claude Code behavior.

---

## Approaches

### Option A: Fully Automated (Deprecated)
- ⚠️ **Risk**: Bot running 24/7 without human trigger
- Matches pattern of recently-blocked "harnesses"
- **See**: [distributed-claude-charity.md](distributed-claude-charity.md) (historical reference)

### Option B: Human-Approved Autonomous (Recommended)
- ✅ **Compliant**: Human approves, then RALPH executes
- Periodic prompt shows available issues
- One click to start autonomous work
- **See**: [claude-tithe-option-b-human-loop.md](claude-tithe-option-b-human-loop.md)

### Option C: Centralized API-Funded (For Scale)
- ✅ **Compliant**: Commercial API designed for automation
- Automatic discovery across GitHub ecosystem
- Requires funding ($270-$15K/month)
- **See**: [claude-tithe-option-c-centralized.md](claude-tithe-option-c-centralized.md)

---

## Quick Comparison

| Feature | Option A | **Option B** | Option C |
|---------|----------|--------------|----------|
| Human trigger | ❌ None | ✅ **One click** | ❌ None |
| ToS compliance | ⚠️ Risk | ✅ **Compliant** | ✅ Compliant |
| Cost | Free | **Free** | $270-15K/mo |
| Like RALPH? | No | **Yes** | No |

---

## Recommended Path

1. **Build Option B** - Same pattern as RALPH, zero cost, zero risk
2. **If funding available** - Add Option C for ecosystem-wide impact
3. **Never deploy Option A** - Unless Anthropic creates charitable compute program

---

## Documentation

- [Option B: Human-Approved Autonomous](claude-tithe-option-b-human-loop.md) - **Start here**
- [Option C: Centralized API-Funded](claude-tithe-option-c-centralized.md) - For scale with funding
- [Terms of Service Analysis](claude-tithe-tos-analysis.md) - Compliance rationale
- [Original Design (Deprecated)](distributed-claude-charity.md) - Historical reference

---

*Last updated: 2026-01-14*
