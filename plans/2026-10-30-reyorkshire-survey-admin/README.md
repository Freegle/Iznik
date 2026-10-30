# ReYorkshire survey ADMIN (York and North Yorkshire)

- `admin.mjml` is the designed version and `admin.txt` the plain-text version. They say the same thing.
- Preview: one test sent 2026-10-30 to edward@ehibbert.org.uk, as a York Freegle member would get it (background task 7987472).
- Second preview sent 2026-10-30 without the volunteer names (task 7988471). Production has no `volunteercredits` switch yet, so it was sent with no community, which also drops the "sent to members of" line.
- `go-live.sql` inserts one already-approved Newsletter ADMIN per group, so no group has to approve it. The next `mail:admin:send` run sends it to members active in the last six months. The ADMINs have `volunteercredits = 0`, so they do not name the local volunteers. It needs the migration and the batch change on this branch deployed first. It has not been run.

Groups, chosen as those whose centre is inside the City of York or North Yorkshire polygon in `authorities`:

| id | group | approved members |
|---|---|---|
| 21707 | York Freegle | 9981 |
| 21453 | Harrogate Freegle | 14522 |
| 126575 | Skipton and Craven Freegle | 8902 |
| 21655 | Northallerton and Thirsk Freegle | 5493 |
| 386071 | Selby Freegle | 2935 |
| 21604 | Scarborough and Whitby Freegle | 1179 |
| 21600 | Ryedale Freegle | 632 |

Left out: Carnforth (37% of its area is in North Yorkshire) and Darlington (19%).
