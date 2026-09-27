Scripts and labels for `../2026-09-22-jev-structured-judgement-evaluation.md`.

Order: pull the pool TSV with the query in the note, then `match.mjs`, `sample.mjs`,
`jev.mjs` (needs `TYPESAFE_API_KEY` in the environment, never in the repo), then
`eval.mjs`, `stab.mjs`, `auc.mjs`. All read `$SD`, the working directory holding
`pool.tsv`.

`labels.txt` is the 160 blind human labels, `<id>Y` relevant and `<id>N` junk, keyed to
the ids `sample.mjs` assigns from the fixed seed 20260922. The seed makes the sample
reproducible, so the labels stay joined to the right pairs.

`prototypes.json` is extracted verbatim from `ContentEmbeddingService::PROTOTYPES` so
`proto.mjs` can replicate the live suppression rule. Re-extract it rather than editing it,
or the replication stops matching the service.

None of these scripts carry data. They read a pool TSV and the llm-modbot test file, both of
which stay out of this repository: `llm-modbot/.gitignore` excludes `data/` and `results/`
because they hold production moderation content, and this repository is public.
