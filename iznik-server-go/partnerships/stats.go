package partnerships

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// Generating a council's quarterly statistics spreadsheet takes minutes, not the seconds a
// web request can wait for, so the page queues a job and polls for it. The Laravel scheduler
// picks the job up (partnerships:stats:run), renders the spreadsheets and stores the bytes
// in partnerships_statsfiles; the download below streams them back out.

// StatsFile is one rendered spreadsheet belonging to a job.
type StatsFile struct {
	ID       uint64 `json:"id"`
	Jobid    uint64 `json:"jobid"`
	Filename string `json:"filename"`
	Size     uint32 `json:"size"`
}

// StatsJob is a queued request to render authority statistics spreadsheets.
type StatsJob struct {
	ID           uint64      `json:"id"`
	Authorityids string      `json:"authorityids"`
	Quarter      string      `json:"quarter"`
	Status       string      `json:"status"`
	Error        *string     `json:"error"`
	Requested    string      `json:"requested"`
	Completed    *string     `json:"completed"`
	Files        []StatsFile `json:"files"`
}

// maxStatsJobs is how many recent jobs the page shows. Older ones are still in the table but
// are of no interest once their spreadsheets have been downloaded.
const maxStatsJobs = 20

// CreateStatsJob queues a spreadsheet generation run.
//
// @Summary Queue authority statistics generation
// @Tags partnerships
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/statsjob [post]
func CreateStatsJob(c *fiber.Ctx) error {
	myid, err := requireUser(c)
	if err != nil {
		return err
	}

	var req struct {
		Authorityids []utils.FlexUint64 `json:"authorityids"`
		Quarter      string             `json:"quarter"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	ids := []string{}
	for _, id := range req.Authorityids {
		if id > 0 {
			ids = append(ids, strconv.FormatUint(uint64(id), 10))
		}
	}

	if len(ids) == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing authorityids"})
	}

	quarter := req.Quarter
	if quarter == "" {
		// The same default the command has: the last full quarter.
		quarter = "3 months ago"
	}

	db := database.DBConn

	row := map[string]interface{}{
		"userid": myid,
		// The command's --i option takes a comma-separated list.
		"authorityids": strings.Join(ids, ","),
		"quarter":      quarter,
		"status":       "Pending",
	}

	if err := db.Table("partnerships_statsjobs").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}

	newIDInt, _ := row["@id"].(int64)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": uint64(newIDInt)})
}

// ListStatsJobs returns the recent generation runs with the files each produced, which is
// what the page polls while a run is in progress.
//
// @Summary List authority statistics generation jobs
// @Tags partnerships
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/statsjob [get]
func ListStatsJobs(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	db := database.DBConn

	var jobs []StatsJob
	db.Table("partnerships_statsjobs").
		Select("id, authorityids, quarter, status, error, " +
			"DATE_FORMAT(requested, '%Y-%m-%d %H:%i:%s') AS requested, " +
			"DATE_FORMAT(completed, '%Y-%m-%d %H:%i:%s') AS completed").
		Order("id DESC").
		Limit(maxStatsJobs).
		Scan(&jobs)

	if len(jobs) == 0 {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "jobs": []StatsJob{}})
	}

	jobids := make([]uint64, 0, len(jobs))
	for _, j := range jobs {
		jobids = append(jobids, j.ID)
	}

	// One query for all the files, then attach - a per-job query would be N+1 on a page
	// that polls every few seconds.
	var files []StatsFile
	db.Table("partnerships_statsfiles").
		Select("id, jobid, filename, size").
		Where("jobid IN ?", jobids).
		Order("id ASC").
		Scan(&files)

	byJob := map[uint64][]StatsFile{}
	for _, f := range files {
		byJob[f.Jobid] = append(byJob[f.Jobid], f)
	}

	for i := range jobs {
		jobs[i].Files = byJob[jobs[i].ID]
		if jobs[i].Files == nil {
			jobs[i].Files = []StatsFile{}
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "jobs": jobs})
}

// DownloadStatsFile streams one rendered spreadsheet.
//
// @Summary Download a generated statistics spreadsheet
// @Tags partnerships
// @Produce application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
// @Param id path integer true "File ID"
// @Success 200 {file} binary
// @Router /api/partnership/statsfile/{id} [get]
func DownloadStatsFile(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn

	var file struct {
		Filename string
		Content  []byte
	}
	db.Table("partnerships_statsfiles").
		Select("filename, content").
		Where("id = ?", id).
		Scan(&file)

	if file.Filename == "" {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	c.Set("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
	// The browser saves it under the council's own name rather than a bare "statsfile".
	c.Set("Content-Disposition", "attachment; filename=\""+sanitiseFilename(file.Filename)+"\"")

	return c.Send(file.Content)
}

// DeleteStatsJob discards a generation run and the spreadsheets it produced.
//
// @Summary Delete a statistics generation job
// @Tags partnerships
// @Produce json
// @Param id path integer true "Job ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/statsjob/{id} [delete]
func DeleteStatsJob(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn
	db.Table("partnerships_statsjobs").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// sanitiseFilename keeps a generated filename safe to put in a Content-Disposition header:
// quotes and control characters would let a crafted authority name break out of it.
func sanitiseFilename(name string) string {
	out := make([]rune, 0, len(name))
	for _, r := range name {
		if r < 32 || r == '"' || r == '\\' || r == '/' {
			out = append(out, '_')

			continue
		}
		out = append(out, r)
	}

	if len(out) == 0 {
		return "statistics.xlsx"
	}

	return string(out)
}
