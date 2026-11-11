# Auto-split: how many posts are several items, and how well the splitter does

Measured 2026-11-07 for `plans/token-burn/auto-split-multi-item-posts.md` (tasks 1 and 6).
Counts only: the posts were read from the live database (read only) and kept outside the
repository.

## The sample

30 days of native Freegle OFFERs (`source = 'Platform'`, not deleted): 19,319 posts, about 640 a
day, with 37,520 photos. Over half have one photo, and one in six has four or more.

## How many posts are several items

Text splitter (`TextSplitter`, prompt as shipped, `gemini-3.8-flash`) over 1,500 posts it was
never tuned on:

| Kind | Posts | Share |
|---|---|---|
| One thing | 791 | 53% |
| A bundle for one person | 257 | 17% |
| Several of the same thing | 170 | 11% |
| One thing with its accessories | 144 | 10% |
| A set | 92 | 6% |
| Several separate things (split) | 45 | 3.0% |

So about 3% of native OFFERs split, around 20 a day. Three in four split into two items, the
rest into three or four. An earlier, less cautious prompt split 4.7% of a different 3,000; the
difference is mostly small things and vague lots ("books and prints", "jigsaws and a chess set")
that the shipped prompt keeps together.

A third of the posts that split had no comma, "and" or "+" in the subject ("Bikes", with three
different bikes in the description), which is why the model reads the whole post instead of a
separator test.

## Does a reviewer agree with the splits

A stronger model (`gemini-3.1-pro-preview`) labelled the same posts blind, from the rules as the
decisions state them, not from the splitter's prompt.

- **Splits:** the reviewer agreed with 41 of 45 (91%). Of the four it did not: two were a bed
  and its mattress, which the decisions split on purpose (a mattress and a frame are each wanted
  on their own); one was a 3-seater and a 2-seater sofa, which the rules also split; one was two
  measuring jugs of different sizes. Read by hand, no split was wrong in a way that would mislead
  a replier.
- **Not split:** of 300 posts the splitter left alone, the reviewer would have split 8 (2.7%).
  Most are small things the rules deliberately keep together (dog collars and a lead, jigsaws, two
  pairs of boots); one was a real miss that the splitter saw but was not confident about.

So the splitter is cautious: when it acts, it is right; it misses some posts that could have been
split, which leaves them as they are today.

## Photos

The photo step (`PhotoSplitter` and `PhotoDecision`, through the PHP path, `autosplit:backtest`)
on the 42 split posts above that had photos, 132 photos in all:

| What a photo became | Photos | Share |
|---|---|---|
| Given whole to one item | 66 | 50% |
| Cropped into one photo per item | 5 (12 crops) | 4% |
| Kept as a shared photo: the items overlap too much to cut cleanly | 51 | 39% |
| Kept as a shared photo: it shows none of the items (a label, a detail) | 9 | 7% |
| Kept as a shared photo: a crop would be a sliver | 1 | 1% |

52 of the 95 items (55%) end up with a photo of their own. The rest show without a thumbnail,
and the post still has all its own photos.

The same reviewer model then looked at every photo an item ends up with:

- **Crops:** all 12 show the whole of their item and nothing a replier could take for another
  item. With every photo of a post in one request, 2 of 8 crops were confusable; one photo a
  request is what fixed it.
- **Whole photos:** 65 of 66 show their item. 6 (9%) also show enough of another listed item to
  confuse, such as a close-up of part of one of two sofas, or a photo of a cupboard in a post that
  also lists a cabinet. In 40 of the 66 the item is cut off at the edge,
  which is how the member took the photo; the splitter does not change it.

## Cost

About 1,000 tokens in and 450 out (thinking included) per post for the text step, every native
OFFER; the photo step adds one request per photo for split posts only. At around 640 posts and
20 splits a day that is well under a pound a day at current Flash prices.

## What changed because of the measurement

- The prompt went through five rounds, each checked on posts it had not seen: what the member
  says about going together decides it; accessories, remotes and parts stay with their main
  thing; small or cheap things listed together are a bundle; only substantial things of one kind
  (bikes, sofas, freezers) are separated by make or size.
- Unconfident answers never split.
- Photos go to the model one at a time: with several in one request it left an item out of a
  group photo it also appeared in.
- The crop rules were set by looking at crops: at a fifth of another item a crop still reads as
  its own; at a quarter it starts to confuse.
