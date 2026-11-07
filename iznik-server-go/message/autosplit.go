package message

import (
	"errors"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// An auto-split post is an ordinary member's Offer that the batch auto-splitter turned into
// an item list: messages_bulk_items rows like a clearance, and a messages_autosplit row in
// state Split, which is what tells it apart from one. It gets the item list, per-item photos
// and per-item taken, and none of the clearance extras. The splitter itself is in the batch
// (App\Services\AutoSplit). docs/developers/reference/auto-split.md.

const (
	autosplitStateSplit  = "Split"
	autosplitStateUndone = "Undone"
)

var errAlreadyUndone = errors.New("autosplit: already undone")

// isAutoSplit reports whether the post is an automatic split that has not been undone.
func isAutoSplit(db *gorm.DB, msgid uint64) bool {
	var n int64
	db.Table("messages_autosplit").Where("msgid = ? AND state = ?", msgid, autosplitStateSplit).Count(&n)
	return n > 0
}

// cropAttachmentIDs returns the post's crop rows: the per-item photos the splitter cut out of
// a photo of several items. Only the splitter writes a crop.
func cropAttachmentIDs(db *gorm.DB, msgid uint64) []uint64 {
	type row struct {
		ID           uint64
		Externalmods string
	}
	var rows []row
	db.Table("messages_attachments").Select("id, COALESCE(externalmods, '') AS externalmods").
		Where("msgid = ? AND externalmods LIKE ?", msgid, `%"cw"%`).Scan(&rows)
	ids := make([]uint64, 0, len(rows))
	for _, r := range rows {
		if misc.IsCrop(r.Externalmods) {
			ids = append(ids, r.ID)
		}
	}
	return ids
}

// deleteAttachments removes photo rows and their item links.
func deleteAttachments(db *gorm.DB, ids []uint64) {
	if len(ids) == 0 {
		return
	}
	db.Table("messages_bulk_item_attachments").Where("attachmentid IN ?", ids).Delete(nil)
	db.Table("messages_attachments").Where("id IN ?", ids).Delete(nil)
}

// keepCropsOfKeptPhotos extends the list of photos a poster is keeping with the crops cut
// from them. A poster's edit sends the photos they can see, which never include the crops,
// and everything not in the list is deleted; so a crop survives while the photo it was cut
// from is kept, and goes when that photo goes.
func keepCropsOfKeptPhotos(db *gorm.DB, msgid uint64, keep []uint64) []uint64 {
	crops := cropAttachmentIDs(db, msgid)
	if len(crops) == 0 || len(keep) == 0 {
		return keep
	}
	var keptUIDs []string
	db.Table("messages_attachments").Where("id IN ? AND msgid = ?", keep, msgid).
		Where("externaluid IS NOT NULL AND externaluid <> ''").Pluck("externaluid", &keptUIDs)
	if len(keptUIDs) == 0 {
		return keep
	}
	var survivors []uint64
	db.Table("messages_attachments").Where("id IN ? AND externaluid IN ?", crops, keptUIDs).Pluck("id", &survivors)
	return append(keep, survivors...)
}

// deleteOrphanCrops removes crops no longer on any item, after an edit removed their item.
func deleteOrphanCrops(db *gorm.DB, msgid uint64) {
	crops := cropAttachmentIDs(db, msgid)
	if len(crops) == 0 {
		return
	}
	var linked []uint64
	db.Table("messages_bulk_item_attachments bia").
		Joins("INNER JOIN messages_bulk_items bi ON bi.id = bia.bulkitemid").
		Where("bia.attachmentid IN ? AND bi.msgid = ?", crops, msgid).Pluck("bia.attachmentid", &linked)
	have := make(map[uint64]bool, len(linked))
	for _, id := range linked {
		have[id] = true
	}
	var orphans []uint64
	for _, id := range crops {
		if !have[id] {
			orphans = append(orphans, id)
		}
	}
	deleteAttachments(db, orphans)
}

// withoutCrops drops the crop rows from a post's photo list. They are shown with their
// items; in the post's own photos they would repeat the photo they were cut from.
func withoutCrops(atts []MessageAttachment) []MessageAttachment {
	out := make([]MessageAttachment, 0, len(atts))
	for _, a := range atts {
		if !misc.IsCrop(string(a.Externalmods)) {
			out = append(out, a)
		}
	}
	return out
}

// markSplitItemsGone records that every item still on offer went when an automatic split is
// marked Taken as a whole. Its stats are counted per item, as a clearance's are, and the
// whole-post outcome is left out of them, so without this the post would count nothing.
func markSplitItemsGone(db *gorm.DB, msgid uint64) {
	if !isAutoSplit(db, msgid) {
		return
	}
	db.Table("messages_bulk_items").Where("msgid = ? AND available = 1", msgid).Update("available", 0)
	recomputeBulkAvailableNow(db, msgid)
}

// ownerOrMod loads the post and checks the caller may change it.
func ownerOrMod(db *gorm.DB, myid uint64, msgid uint64) error {
	if msgid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", msgid).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if fromuser != myid && !isModForMessage(db, myid, msgid) {
		return fiber.NewError(fiber.StatusForbidden, "Not your post")
	}
	return nil
}

// handleAutoSplitUndo takes back an automatic split: the items, their links and the crops go,
// and the availability counts are put back, so the post is exactly as it was written. The
// post is never split again. Owner or moderator, while the post has no outcome.
func handleAutoSplitUndo(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn
	if err := ownerOrMod(db, myid, req.ID); err != nil {
		return err
	}

	type splitRow struct {
		ID                         uint64
		Originalavailableinitially *int
		Originalavailablenow       *int
	}
	var row splitRow
	db.Table("messages_autosplit").Select("id, originalavailableinitially, originalavailablenow").
		Where("msgid = ? AND state = ?", req.ID, autosplitStateSplit).Scan(&row)
	if row.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "This post was not split automatically")
	}

	var outcomes int64
	db.Table("messages_outcomes").Where("msgid = ?", req.ID).Count(&outcomes)
	if outcomes > 0 {
		return fiber.NewError(fiber.StatusConflict, "This post already has an outcome")
	}

	initially, now := 1, 1
	if row.Originalavailableinitially != nil {
		initially = *row.Originalavailableinitially
	}
	if row.Originalavailablenow != nil {
		now = *row.Originalavailablenow
	}

	err := db.Transaction(func(tx *gorm.DB) error {
		// The state change claims the undo, so two at once cannot both run.
		res := tx.Table("messages_autosplit").Where("id = ? AND state = ?", row.ID, autosplitStateSplit).
			Updates(map[string]interface{}{"state": autosplitStateUndone, "undoneat": gorm.Expr("NOW()")})
		if res.Error != nil {
			return res.Error
		}
		if res.RowsAffected == 0 {
			return errAlreadyUndone
		}
		deleteAttachments(tx, cropAttachmentIDs(tx, req.ID))
		// Items cascade to their photo links and any interest.
		if err := tx.Table("messages_bulk_items").Where("msgid = ?", req.ID).Delete(nil).Error; err != nil {
			return err
		}
		return tx.Table("messages").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"availableinitially": initially, "availablenow": now}).Error
	})
	if errors.Is(err, errAlreadyUndone) {
		return fiber.NewError(fiber.StatusBadRequest, "This post was not split automatically")
	}
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not undo the split")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleBulkItemAvailable marks one item of a multi-item post as gone, or back on offer. Owner
// or moderator. For a clearance, the last item going records the post as Taken, as the
// logged-out update page does. An automatic split is left for its poster to mark Taken in the
// usual way, which also asks who took it.
func handleBulkItemAvailable(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn
	if err := ownerOrMod(db, myid, req.ID); err != nil {
		return err
	}
	if req.Bulkitemid == nil || req.Available == nil {
		return fiber.NewError(fiber.StatusBadRequest, "bulkitemid and available are required")
	}

	var owning uint64
	db.Table("messages_bulk_items").Select("msgid").Where("id = ?", *req.Bulkitemid).Scan(&owning)
	if owning != req.ID {
		return fiber.NewError(fiber.StatusNotFound, "Item not found")
	}

	av := 0
	if *req.Available {
		av = 1
	}
	db.Table("messages_bulk_items").Where("id = ? AND msgid = ?", *req.Bulkitemid, req.ID).Update("available", av)
	recomputeBulkAvailableNow(db, req.ID)
	if !isAutoSplit(db, req.ID) {
		recordBulkOutcomeIfComplete(db, req.ID)
	}

	var availablenow int
	db.Table("messages").Select("availablenow").Where("id = ?", req.ID).Scan(&availablenow)
	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "availablenow": availablenow})
}

// splitItemsEdit turns the item list a poster sends for an automatic split into the one that
// is written. A split post's items can be taken off the list or recounted, and nothing else.
// The names come from the member's own words, and a rename would skip the review a text edit
// gets for a moderated member. The photos are the ones the split gave each item, so no other
// post's photo can be linked, and no photo link is fetched. Undo is the way to change more.
func splitItemsEdit(db *gorm.DB, msgid uint64, in []BulkItemInput) ([]BulkItemInput, error) {
	type itemRow struct {
		ID        uint64
		Name      string
		Condition string
	}
	var rows []itemRow
	db.Table("messages_bulk_items").Select("id, name, `condition`").Where("msgid = ?", msgid).Scan(&rows)
	byID := make(map[uint64]itemRow, len(rows))
	for _, r := range rows {
		byID[r.ID] = r
	}

	out := make([]BulkItemInput, 0, len(in))
	seen := map[uint64]bool{}
	for _, it := range in {
		r, ok := byID[it.ID]
		if !ok || seen[it.ID] {
			return nil, fiber.NewError(fiber.StatusBadRequest, "Items can only be taken off the list or recounted")
		}
		if name := strings.TrimSpace(it.Name); name != "" && name != r.Name {
			return nil, fiber.NewError(fiber.StatusBadRequest, "Item names cannot be changed. To change the post, undo the split")
		}
		seen[it.ID] = true

		qty := it.Quantity
		if qty < 1 {
			qty = 1
		}
		if qty > maxSplitQuantity {
			qty = maxSplitQuantity
		}
		atts := []uint64{}
		db.Table("messages_bulk_item_attachments").Where("bulkitemid = ?", it.ID).Pluck("attachmentid", &atts)
		out = append(out, BulkItemInput{ID: it.ID, Name: r.Name, Quantity: qty, Condition: r.Condition, Attachments: atts})
	}

	if len(out) < 2 {
		return nil, fiber.NewError(fiber.StatusBadRequest, "Keep at least two items, or undo the split")
	}
	return out, nil
}

// maxSplitQuantity matches the splitter's own limit (TextSplitter::MAX_QUANTITY).
const maxSplitQuantity = 99
