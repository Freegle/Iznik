// Package router provides routing for the API
//
// @title Iznik API
// @version 1.0
// @description The Iznik API provides access to functionality for freegling (free reuse) groups.  See README.md for more info.
// @termsOfService https://www.ilovefreegle.org/terms
//
// @contact.name Freegle Geeks
// @contact.url https://www.ilovefreegle.org/help
// @contact.email geeks@ilovefreegle.org
//
// @license.name GPL v2
// @license.url https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html
//
// @host api.ilovefreegle.org
// @BasePath /api
// @query.collection.format multi
//
// @securityDefinitions.apikey BearerAuth
// @in header
// @name Authorization
package router

import (
	"github.com/freegle/iznik-server-go/abtest"
	"github.com/freegle/iznik-server-go/address"
	"github.com/freegle/iznik-server-go/admin"
	"github.com/freegle/iznik-server-go/aiimage"
	"github.com/freegle/iznik-server-go/alert"
	"github.com/freegle/iznik-server-go/amp"
	"github.com/freegle/iznik-server-go/authority"
	"github.com/freegle/iznik-server-go/avatar"
	"github.com/freegle/iznik-server-go/browse"
	"github.com/freegle/iznik-server-go/changes"
	"github.com/freegle/iznik-server-go/charity"
	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/clientlog"
	"github.com/freegle/iznik-server-go/comment"
	"github.com/freegle/iznik-server-go/communityevent"
	"github.com/freegle/iznik-server-go/config"
	"github.com/freegle/iznik-server-go/dashboard"
	"github.com/freegle/iznik-server-go/deprecation"
	"github.com/freegle/iznik-server-go/domain"
	"github.com/freegle/iznik-server-go/donations"
	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/freegle/iznik-server-go/export"
	"github.com/freegle/iznik-server-go/group"
	"github.com/freegle/iznik-server-go/housekeeper"
	"github.com/freegle/iznik-server-go/image"
	"github.com/freegle/iznik-server-go/isochrone"
	"github.com/freegle/iznik-server-go/item"
	"github.com/freegle/iznik-server-go/job"
	"github.com/freegle/iznik-server-go/location"
	"github.com/freegle/iznik-server-go/logs"
	"github.com/freegle/iznik-server-go/membership"
	"github.com/freegle/iznik-server-go/merge"
	"github.com/freegle/iznik-server-go/message"

	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/modconfig"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/noticeboard"
	"github.com/freegle/iznik-server-go/notification"
	"github.com/freegle/iznik-server-go/recommendations"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/session"
	"github.com/freegle/iznik-server-go/shortlink"
	"github.com/freegle/iznik-server-go/simulation"
	"github.com/freegle/iznik-server-go/spammers"
	"github.com/freegle/iznik-server-go/src"
	"github.com/freegle/iznik-server-go/sso"
	"github.com/freegle/iznik-server-go/status"
	"github.com/freegle/iznik-server-go/stdmsg"
	"github.com/freegle/iznik-server-go/story"
	"github.com/freegle/iznik-server-go/systemlogs"
	"github.com/freegle/iznik-server-go/team"
	"github.com/freegle/iznik-server-go/town"
	"github.com/freegle/iznik-server-go/tryst"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/userdump"
	"github.com/freegle/iznik-server-go/visualise"
	"github.com/freegle/iznik-server-go/volunteering"
	"github.com/gofiber/fiber/v2"
)

// SetupRoutes registers all API routes
// @Summary Setup all API routes
// @Description Configures both /api and /apiv2 route groups
func SetupRoutes(app *fiber.App) {
	// We have two groups because of how the API is used in the old and new clients.
	api := app.Group("/api")
	apiv2 := app.Group("/apiv2")

	for _, rg := range []fiber.Router{api, apiv2} {
		// A/B Test GET
		// @Router /abtest [get]
		// @Summary Get A/B test variant
		// @Description Returns the best-performing variant for a test UID using epsilon-greedy bandit
		// @Tags abtest
		// @Produce json
		rg.Get("/abtest", abtest.GetABTest)

		// A/B Test POST
		// @Router /abtest [post]
		// @Summary Track A/B test event
		// @Description Record a shown or action event for a variant
		// @Tags abtest
		// @Accept json
		// @Produce json
		rg.Post("/abtest", abtest.PostABTest)

		// Browse-feed scroll depth: record how far down the feed a session scrolled.
		// @Router /scrolldepth [post]
		// @Summary Record browse-feed scroll depth
		// @Description One row per browse session (furthest feed position reached); no login required
		// @Tags browse
		// @Accept json
		// @Produce json
		rg.Post("/scrolldepth", browse.RecordScrollDepth)

		// Message Activity
		// @Router /activity [get]
		// @Summary Get recent activity
		// @Description Returns the most recent activity in groups
		// @Tags message
		// @Produce json
		// @Success 200 {array} message.Activity
		rg.Get("/activity", deprecation.Marker("GET /activity", "2026-08-01"), message.GetRecentActivity)

		// Lists the endpoints currently wrapped in deprecation.Marker() + their
		// sunset dates, for the nightly monitor:deprecated-endpoints report.
		rg.Get("/deprecated", deprecation.GetDeprecated)

		// User Addresses
		// @Router /address [get]
		// @Summary List addresses for user
		// @Description Returns all addresses for the authenticated user
		// @Tags address
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {array} address.Address
		rg.Get("/address", address.ListForUser)

		// Single Address
		// @Router /address/{id} [get]
		// @Summary Get address by ID
		// @Description Returns a single address by ID
		// @Tags address
		// @Produce json
		// @Param id path integer true "Address ID"
		// @Success 200 {object} address.Address
		// @Failure 404 {object} fiber.Error "Address not found"
		rg.Get("/address/:id", address.GetAddress)

		// Create Address
		// @Router /address [put]
		// @Summary Create a new address
		// @Tags address
		// @Accept json
		// @Produce json
		rg.Put("/address", address.Create)

		// Update Address
		// @Router /address [patch]
		// @Summary Update an existing address
		// @Tags address
		// @Accept json
		// @Produce json
		rg.Patch("/address", address.Update)

		// Delete Address
		// @Router /address/{id} [delete]
		// @Summary Delete an address
		// @Tags address
		// @Param id path integer true "Address ID"
		rg.Delete("/address/:id", address.Delete)

		// Alerts
		// @Router /alert [get]
		// @Summary List all alerts
		// @Description Returns all alerts (Admin/Support only)
		// @Tags alert
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/modtools/alert", alert.ListAlerts)

		// @Router /alert/{id} [get]
		// @Summary Get alert by ID
		// @Description Returns a single alert by ID (public access)
		// @Tags alert
		// @Produce json
		// @Param id path integer true "Alert ID"
		// @Success 200 {object} map[string]interface{}
		rg.Get("/modtools/alert/:id", deprecation.Marker("GET /modtools/alert/:id", "2026-08-01"), alert.GetAlert)

		// @Router /alert [put]
		// @Summary Create a new alert
		// @Description Creates a new alert (Admin/Support only)
		// @Tags alert
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Put("/modtools/alert", alert.CreateAlert)

		// @Router /alert [post]
		// @Summary Record alert click
		// @Description Records a click on an alert tracking entry (public access)
		// @Tags alert
		// @Accept json
		// @Produce json
		// @Success 200 {object} map[string]interface{}
		rg.Post("/modtools/alert", alert.RecordAlert)

		// Admin
		rg.Get("/modtools/admin", admin.ListAdmins)
		rg.Get("/modtools/admin/:id", deprecation.Marker("GET /modtools/admin/:id", "2026-08-01"), admin.GetAdmin)
		rg.Post("/modtools/admin", admin.PostAdmin)
		rg.Patch("/modtools/admin", admin.PatchAdmin)
		rg.Delete("/modtools/admin", admin.DeleteAdmin)

		// AI Image regeneration (support/admin only)
		rg.Get("/admin/ai-images/review", aiimage.ListReview)
		rg.Get("/admin/ai-images/count", aiimage.Count)
		rg.Post("/admin/ai-images/:id/regenerate", aiimage.Regenerate)
		rg.Post("/admin/ai-images/:id/accept", aiimage.Accept)
		rg.Post("/admin/ai-images/:id/keep", aiimage.KeepCurrent)
		rg.Post("/admin/ai-images/:id/suppress", aiimage.Suppress)

		// Authority Search
		// @Router /authority [get]
		// @Summary Search authorities
		// @Description Searches authorities by name
		// @Tags authority
		// @Produce json
		// @Param search query string true "Search term"
		// @Param limit query integer false "Maximum results (default 10)"
		// @Success 200 {array} authority.SearchResult
		rg.Get("/authority", authority.Search)

		// Single Authority
		// @Router /authority/{id} [get]
		// @Summary Get authority by ID
		// @Description Returns a single authority by ID with polygon, centre, and overlapping groups
		// @Tags authority
		// @Produce json
		// @Param id path integer true "Authority ID"
		// @Success 200 {object} authority.Authority
		// @Failure 404 {object} fiber.Error "Authority not found"
		rg.Get("/authority/:id", authority.Single)

		// Authority Messages
		// @Router /authority/{id}/message [get]
		// @Summary Get messages for authority
		// @Description Returns messages for a specific authority
		// @Tags authority
		// @Produce json
		// @Param id path integer true "Authority ID"
		// @Success 200 {array} authority.Message
		rg.Get("/authority/:id/message", authority.Messages)

		// Item impact estimate
		// @Router /item/impact [get]
		// @Summary Estimate reuse impact for an item name
		// @Description Estimates weight, CO2e saved and financial benefit of reuse for qty units of a free-text item name. Public, read-only - never writes to the items catalog. Lookup order: (1) exact case-insensitive match against the items catalog with a known weight, (2) fuzzy word-overlap match (>10%) against the standard weights reference table, (3) popularity-weighted average item weight.
		// @Tags item
		// @Produce json
		// @Param name query string true "Free-text item name"
		// @Param qty query integer false "Quantity (default 1)"
		// @Success 200 {object} item.ImpactResponse
		// @Failure 400 {object} fiber.Error "Missing or empty name"
		rg.Get("/item/impact", item.Impact)

		// Chats
		// @Router /chat [get]
		// @Summary List chats for user
		// @Description Returns all chats for the authenticated user. Pass keepChat to ensure a specific (possibly old) chat is included even if its lastdate predates the normal lookback window.
		// @Tags chat
		// @Produce json
		// @Security BearerAuth
		// @Param since query string false "Only return chats with activity since this RFC3339 timestamp"
		// @Param search query string false "Search term to filter chats"
		// @Param keepChat query integer false "Chat ID to include unconditionally (bypasses date cutoff, must be a chat the caller is a member of)"
		// @Param includeClosed query boolean false "Whether to include closed/blocked chats"
		// @Param chattypes query array false "Chat types to include (User2User, User2Mod, Mod2Mod)"
		// @Success 200 {array} chat.ChatRoomListEntry
		rg.Get("/chat", chat.ListForUser)

		// Chat Rooms MT List
		// @Router /chat/rooms [get]
		// @Summary List chat rooms for moderator
		// @Description Returns chat rooms filtered by chat type for moderator view
		// @Tags chat
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/chat/rooms", chat.ListForUserMT)

		// Chat Messages
		// @Router /chat/{id}/message [get]
		// @Summary Get chat messages
		// @Description Returns messages for a specific chat
		// @Tags chat
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Security BearerAuth
		// @Success 200 {array} chat.ChatMessage
		rg.Get("/chat/:id/message", chat.GetChatMessages)

		// @Router /chat/{id}/commongroups [get]
		// @Summary Groups in common between the two chat participants
		// @Tags chat
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Security BearerAuth
		// @Success 200 {array} chat.CommonGroup
		rg.Get("/chat/:id/commongroups", chat.GetCommonGroups)

		// Create Chat Message
		// @Router /chat/{id}/message [post]
		// @Summary Create chat message
		// @Description Creates a new message in a chat
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Param message body chat.ChatMessage true "Chat message object"
		// @Security BearerAuth
		// @Success 200 {object} chat.ChatMessage
		rg.Post("/chat/:id/message", chat.CreateChatMessage)

		// Answer a Freegle prompt
		// @Router /chat/{id}/message/{mid}/prompt [post]
		// @Summary Answer a Freegle chat prompt
		// @Description Records the member's answer to a type='Prompt' chat message and applies
		// @Description it to the posts the prompt covers. Only the member the prompt was sent
		// @Description to may answer, and only once.
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Param mid path integer true "Chat message ID"
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/chat/:id/message/:mid/prompt", chat.AnswerChatPrompt)

		// Patch Chat Message
		// @Router /chatmessages [patch]
		// @Summary Update chat message
		// @Description Updates a chat message (e.g. replyexpected flag)
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Patch("/chatmessages", chat.PatchChatMessage)

		// Delete Chat Message
		// @Router /chatmessages [delete]
		// @Summary Delete chat message
		// @Description Soft-deletes a chat message owned by the logged-in user
		// @Tags chat
		// @Produce json
		// @Param id query integer true "Chat Message ID"
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Delete("/chatmessages", chat.DeleteChatMessage)

		// LoveJunk Chat
		// @Router /chat/lovejunk [post]
		// @Summary Create LoveJunk chat message
		// @Description Creates a new LoveJunk chat message
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Param message body chat.ChatMessage true "Chat message object"
		// @Security BearerAuth
		// @Success 200 {object} chat.ChatMessage
		rg.Post("/chat/lovejunk", chat.CreateChatMessageLoveJunk)

		// Single Chat
		// @Router /chat/{id} [get]
		// @Summary Get chat by ID
		// @Description Returns a single chat by ID
		// @Tags chat
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Security BearerAuth
		// @Success 200 {object} chat.ChatRoom
		// @Failure 404 {object} fiber.Error "Chat not found"
		rg.Get("/chat/:id", chat.GetChat)

		// Chat Rooms MT GET
		// @Router /chatrooms [get]
		// @Summary Get chatrooms for moderator (unseen count or single room)
		// @Description Returns unseen count, single room, or list of chat rooms for moderator
		// @Tags chat
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/chatrooms", chat.GetChatRoomsMT)

		// Chat Messages GET (review queue + room messages)
		// @Router /chatmessages [get]
		// @Summary Get chat messages for review or from specific room
		// @Description Returns review queue messages or messages from a specific chat room
		// @Tags chat
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/chatmessages", chat.GetReviewChatMessages)

		// Chatroom Actions
		// @Router /chatrooms [post]
		// @Summary Chatroom actions (roster update, nudge, typing)
		// @Description Handles roster updates, nudge messages, and typing indicators for chat rooms
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/chatrooms", chat.PostChatRoom)
		rg.Put("/chat/rooms", chat.PutChatRoom)

		// Chat Message Moderation
		// @Router /chatmessages [post]
		// @Summary Moderate chat message
		// @Description Approve, reject, hold, release, or redact a chat message
		// @Tags chat
		// @Accept json
		// @Produce json
		// @Param body body chat.ModerationRequest true "Moderation action"
		// @Security BearerAuth
		// @Success 200 {object} object
		rg.Post("/chatmessages", chat.PostChatMessageModeration)

		// Changes
		// @Router /changes [get]
		// @Summary Get changes since timestamp
		// @Description Returns message changes, user changes, and ratings since a given time. Requires partner key.
		// @Tags changes
		// @Produce json
		// @Param since query string false "ISO8601 timestamp (defaults to 1 hour ago)"
		// @Param partner query string true "Partner API key"
		// @Success 200 {object} map[string]interface{}
		// @Failure 403 {object} fiber.Error "Invalid partner key"
		rg.Get("/changes", changes.GetChanges)

		// Charity Partner signup
		// @Router /charities [post]
		// @Summary Register a charity partner
		// @Tags charities
		// @Accept json
		// @Produce json
		// @Success 200 {object} map[string]interface{}
		rg.Post("/charities", charity.CreateCharity)

		// Client Logging
		// @Router /clientlog [post]
		// @Summary Receive client logs
		// @Description Accepts client-side log entries for distributed tracing
		// @Tags logging
		// @Accept json
		// @Produce json
		// @Param logs body clientlog.ClientLogRequest true "Client log entries"
		// @Success 204 "No Content"
		rg.Post("/clientlog", clientlog.ReceiveClientLogs)

		// Dashboard
		// @Router /dashboard [get]
		// @Summary Get dashboard data
		// @Description Returns dashboard components for moderator/user dashboards
		// @Tags dashboard
		// @Produce json
		// @Param components query string false "Comma-separated component names"
		// @Param group query integer false "Group ID"
		// @Success 200 {object} map[string]interface{}
		rg.Get("/dashboard", dashboard.GetDashboard)

		// Community Events
		// @Router /communityevent [get]
		// @Summary List community events
		// @Description Returns all community events
		// @Tags communityevent
		// @Produce json
		// @Success 200 {array} communityevent.CommunityEvent
		rg.Get("/communityevent", communityevent.List)

		// Group Community Events
		// @Router /communityevent/group/{id} [get]
		// @Summary List community events for group
		// @Description Returns all community events for a specific group
		// @Tags communityevent
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {array} communityevent.CommunityEvent
		rg.Get("/communityevent/group/:id", communityevent.ListGroup)

		// Single Community Event
		// @Router /communityevent/{id} [get]
		// @Summary Get community event by ID
		// @Description Returns a single community event by ID
		// @Tags communityevent
		// @Produce json
		// @Param id path integer true "Community Event ID"
		// @Success 200 {object} communityevent.CommunityEvent
		// @Failure 404 {object} fiber.Error "Community event not found"
		rg.Get("/communityevent/:id", communityevent.Single)
		rg.Post("/communityevent", communityevent.Create)
		rg.Patch("/communityevent", communityevent.Update)
		rg.Delete("/communityevent/:id", communityevent.Delete)

		// Comment
		// @Router /comment [get]
		// @Summary List or get comments
		// @Description Returns comments for moderated groups, with pagination. Pass id for a single comment.
		// @Tags comment
		// @Produce json
		// @Param id query integer false "Comment ID for single fetch"
		// @Param groupid query integer false "Filter by group ID"
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/comment", comment.Get)

		// Comment Write Operations
		// @Router /comment [post]
		// @Summary Create a comment on a user
		// @Description Moderators can add comments to users in their groups
		// @Tags comment
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/comment", comment.Create)

		// @Router /comment [patch]
		// @Summary Edit a comment
		// @Description Moderators can edit comments on users in their groups
		// @Tags comment
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Patch("/comment", comment.Edit)

		// @Router /comment/{id} [delete]
		// @Summary Delete a comment
		// @Description Moderators can delete comments on users in their groups
		// @Tags comment
		// @Produce json
		// @Param id path integer true "Comment ID"
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Delete("/comment/:id", comment.Delete)

		// Config
		// @Router /config/{key} [get]
		// @Summary Get configuration
		// @Description Returns configuration by key
		// @Tags config
		// @Produce json
		// @Param key path string true "Configuration key"
		// @Success 200 {object} config.ConfigItem
		rg.Get("/config/:key", config.Get)

		// Rippling-out live event counters, read-only, Support/Admin only (sysadmin §15/§16).
		// First-reply effectiveness, per lever. Same Support/Admin gate as rippling.
		firstReplyAdmin := rg.Group("/firstreply")
		firstReplyAdmin.Use(config.RequireSupportOrAdminMiddleware())
		firstReplyAdmin.Get("/metrics", firstreply.Metrics)

		ripplingAdmin := rg.Group("/rippling")
		ripplingAdmin.Use(config.RequireSupportOrAdminMiddleware())
		ripplingAdmin.Get("/metrics", rippling.Metrics)
		ripplingAdmin.Get("/analytics", rippling.Analytics)
		ripplingAdmin.Get("/analytics/drivetime", rippling.AnalyticsDriveTimes)
		ripplingAdmin.Post("/analytics/drivetime/score", rippling.AnalyticsDriveScore)
		ripplingAdmin.Post("/analytics/drivetime/aggregate", rippling.AnalyticsDriveAggregate)

		// Create a protected route group for admin endpoints
		adminConfig := rg.Group("/config/admin")
		adminConfig.Use(config.RequireSupportOrAdminMiddleware())

		// @Router /config/admin/concern_keywords [get]
		// @Summary List concern keywords
		// @Tags config
		// @Produce json
		// @Security BearerAuth
		// @Param scope query string false "Filter by scope (global/group)"
		// @Param group_id query string false "Filter by group ID (when scope=group)"
		// @Success 200 {array} config.ConcernKeyword
		// @Failure 401 {object} fiber.Error "Authentication required"
		// @Failure 403 {object} fiber.Error "Support or Admin role required"
		adminConfig.Get("/concern_keywords", config.ListConcernKeywords)

		// @Router /config/admin/concern_keywords [post]
		// @Summary Create a concern keyword
		// @Tags config
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Param body body config.CreateConcernKeywordRequest true "Concern keyword to create"
		// @Success 200 {object} config.ConcernKeyword
		// @Failure 400 {object} fiber.Error "Invalid request"
		// @Failure 401 {object} fiber.Error "Authentication required"
		// @Failure 403 {object} fiber.Error "Support or Admin role required"
		adminConfig.Post("/concern_keywords", config.CreateConcernKeyword)

		// @Router /config/admin/concern_keywords/{id} [delete]
		// @Summary Delete a concern keyword
		// @Tags config
		// @Produce json
		// @Security BearerAuth
		// @Param id path int true "Concern keyword ID"
		// @Success 200 {object} map[string]bool
		// @Failure 401 {object} fiber.Error "Authentication required"
		// @Failure 403 {object} fiber.Error "Support or Admin role required"
		// @Failure 404 {object} fiber.Error "Concern keyword not found"
		adminConfig.Delete("/concern_keywords/:id", config.DeleteConcernKeyword)

		// Admin Config Patch
		// @Router /config/admin [patch]
		// @Summary Update admin config keys
		// @Tags config
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		adminConfig.Patch("", deprecation.Marker("PATCH /config/admin", "2026-08-01"), config.PatchAdminConfig)

		// Groups
		// @Router /group [get]
		// @Summary List groups
		// @Description Returns all groups
		// @Tags group
		// @Produce json
		// @Success 200 {array} group.Group
		rg.Get("/group", group.ListGroups)

		// Per-group work counts for moderators.
		rg.Get("/group/work", group.GetGroupWork)

		// Single Group
		// @Router /group/{id} [get]
		// @Summary Get group by ID
		// @Description Returns a single group by ID
		// @Tags group
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {object} group.Group
		// @Failure 404 {object} fiber.Error "Group not found"
		rg.Get("/group/:id", group.GetGroup)

		// Create Group
		// @Router /group [post]
		// @Summary Create a new group
		// @Tags group
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Post("/group", group.CreateGroup)

		// Group Messages
		// @Router /group/{id}/message [get]
		// @Summary Get messages for group
		// @Description Returns messages for a specific group
		// @Tags group,message
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {array} message.Message
		rg.Get("/group/:id/message", group.GetGroupMessages)

		// Group Message Summaries
		// @Router /group/{id}/message/summary [get]
		// @Summary Get id + subject for a group's live posts
		// @Description Backs the server-rendered, crawlable post list on the community page
		// @Tags group,message
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {array} group.GroupMessageSummary
		rg.Get("/group/:id/message/summary", group.GetGroupMessageSummaries)

		// Group PATCH
		// @Router /group [patch]
		// @Summary Update group settings
		// @Description Update group fields. Requires mod/owner role or admin/support.
		// @Tags group
		// @Accept json
		// @Produce json
		rg.Patch("/group", group.PatchGroup)

		// Noticeboard GET (list)
		// @Router /noticeboard [get]
		// @Summary List noticeboards
		// @Description Returns active noticeboards, optionally filtered by authority
		// @Tags noticeboard
		// @Produce json
		rg.Get("/noticeboard", noticeboard.GetNoticeboard)

		// Noticeboard GET (single)
		// @Router /noticeboard/:id [get]
		// @Summary Get noticeboard by ID
		// @Description Returns noticeboard details with checks and photo
		// @Tags noticeboard
		// @Produce json
		rg.Get("/noticeboard/:id", noticeboard.GetNoticeboard)

		// Noticeboard POST (create + action)
		// @Router /noticeboard [post]
		// @Summary Create noticeboard or perform action
		// @Description Create a new noticeboard (requires lat/lng) or perform an action on existing one
		// @Tags noticeboard
		// @Accept json
		// @Produce json
		rg.Post("/noticeboard", noticeboard.PostNoticeboard)

		// Noticeboard PATCH
		// @Router /noticeboard [patch]
		// @Summary Update noticeboard
		// @Description Update noticeboard fields and optionally link photo
		// @Tags noticeboard
		// @Accept json
		// @Produce json
		rg.Patch("/noticeboard", noticeboard.PatchNoticeboard)

		// Noticeboard DELETE
		// @Router /noticeboard/{id} [delete]
		// @Summary Delete noticeboard
		// @Description Deletes a noticeboard by ID. Requires mod/admin role.
		// @Tags noticeboard
		// @Produce json
		// @Param id path integer true "Noticeboard ID"
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Delete("/noticeboard/:id", deprecation.Marker("DELETE /noticeboard/:id", "2026-08-01"), noticeboard.DeleteNoticeboard)

		// Isochrones
		//
		// DEPRECATED: the per-user isochrone editor was removed in the rippling-out
		// "Nearby = reach" flip (PR #921). No current client (Freegle or ModTools) calls
		// these four CRUD endpoints - the isochrone store/editor that used them was
		// deleted (stores/isochrone.js -> stores/nearby.js; components/IsoChrone.vue
		// removed). Kept only for backward compatibility with any older deployed clients;
		// safe to remove once those have aged out. NOTE: /isochrone/message and
		// /message/count below are NOT deprecated - they still back the Nearby feed and
		// its unseen count.
		// @Router /isochrone [get]
		// @Summary List isochrones
		// @Description [DEPRECATED - no current client calls this; see PR #921] Returns all isochrones
		// @Tags isochrone
		// @Produce json
		// @Deprecated
		// @Success 200 {array} isochrone.Isochrone
		rg.Get("/isochrone", deprecation.Marker("GET /isochrone", "2026-08-01"), isochrone.ListIsochrones)
		rg.Put("/isochrone", deprecation.Marker("PUT /isochrone", "2026-08-01"), isochrone.CreateIsochrone)
		rg.Patch("/isochrone", deprecation.Marker("PATCH /isochrone", "2026-08-01"), isochrone.EditIsochrone)
		rg.Delete("/isochrone", deprecation.Marker("DELETE /isochrone", "2026-08-01"), isochrone.DeleteIsochrone)

		// Isochrone Messages
		// @Router /isochrone/message [get]
		// @Summary Get messages for isochrone
		// @Description Returns messages for isochrones
		// @Tags isochrone,message
		// @Produce json
		// @Success 200 {array} isochrone.Message
		rg.Get("/isochrone/message", isochrone.Messages)

		// Volunteering Write Operations
		rg.Post("/volunteering", volunteering.Create)
		rg.Patch("/volunteering", volunteering.Update)
		rg.Delete("/volunteering/:id", volunteering.Delete)

		// Image Attachments
		// @Router /image [post]
		// @Summary Create or update image attachment
		// @Description Registers an externally-uploaded image (via Tus) or rotates an existing image
		// @Tags image
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/image", image.Post)

		// Legacy image URL resolution
		// @Router /image [get]
		// @Summary Resolve a legacy image URL to a redirect
		// @Description Replaces V1 GET /api/image, which the images.ilovefreegle.org vhost rewrites the old *img_N.jpg URL forms into. Redirects to the delivery CDN (externaluid/externalurl/Azure-archived rows) or the default profile image.
		// @Tags image
		// @Param id query int true "Attachment id"
		// @Param w query int false "Thumbnail width (honoured for archived rows only, matching V1)"
		// @Param h query int false "Thumbnail height (honoured for archived rows only, matching V1)"
		// @Success 302
		rg.Get("/image", image.Get)

		// Jobs
		// @Router /job [get]
		// @Summary List jobs
		// @Description Returns all jobs
		// @Tags job
		// @Produce json
		// @Success 200 {array} job.Job
		rg.Get("/job", job.GetJobs)

		// Single Job
		// @Router /job/{id} [get]
		// @Summary Get job by ID
		// @Description Returns a single job by ID
		// @Tags job
		// @Produce json
		// @Param id path integer true "Job ID"
		// @Success 200 {object} job.Job
		// @Failure 404 {object} fiber.Error "Job not found"
		rg.Get("/job/:id", job.GetJob)

		// Record Job Click
		// @Router /job [post]
		// @Summary Record a job click
		// @Description Records when a user clicks on a job listing for analytics
		// @Tags job
		// @Produce json
		// @Param id query integer true "Job ID"
		// @Param link query string false "Job URL"
		// @Success 200 {object} map[string]interface{} "Success response"
		// @Failure 400 {object} fiber.Error "Job ID required"
		rg.Post("/job", job.RecordJobClick)

		// Location by Lat/Lng
		// @Router /location/latlng [get]
		// @Summary Get location by latitude/longitude
		// @Description Returns location info for given coordinates
		// @Tags location
		// @Produce json
		// @Param lat query number true "Latitude"
		// @Param lng query number true "Longitude"
		// @Success 200 {object} location.Location
		rg.Get("/location/latlng", location.LatLng)

		// Location Typeahead
		// @Router /location/typeahead [get]
		// @Summary Location typeahead search
		// @Description Returns location suggestions for typeahead
		// @Tags location
		// @Produce json
		// @Param term query string true "Search term"
		// @Success 200 {array} location.Location
		rg.Get("/location/typeahead", location.Typeahead)

		// Location Resolve (exact place name -> best matching location)
		// @Router /location/resolve [get]
		// @Summary Resolve an exact place name to a location
		// @Description Returns the single best location for an exact place name (county/town/postcode),
		// @Description used to offer "search near <place>" when an item search returns nothing. 404 if unknown.
		// @Tags location
		// @Produce json
		// @Param name query string true "Exact place name"
		// @Success 200 {object} location.Location
		rg.Get("/location/resolve", location.Resolve)

		// Location Addresses
		// @Router /location/{id}/addresses [get]
		// @Summary Get addresses for location
		// @Description Returns addresses for a specific location
		// @Tags location
		// @Produce json
		// @Param id path integer true "Location ID"
		// @Success 200 {array} address.Address
		rg.Get("/location/:id/addresses", location.GetLocationAddresses)

		// Single Location
		// @Router /location/{id} [get]
		// @Summary Get location by ID
		// @Description Returns a single location by ID
		// @Tags location
		// @Produce json
		// @Param id path integer true "Location ID"
		// @Success 200 {object} location.Location
		// @Failure 404 {object} fiber.Error "Location not found"
		rg.Get("/location/:id", location.GetLocation)

		// Location Search (GET /locations - search by lat/lng, typeahead, or bounding box)
		rg.Get("/locations", location.SearchLocations)
		rg.Get("/town/near", town.Near)

		// Location Write Operations
		rg.Put("/locations", location.CreateLocation)
		rg.Patch("/locations", location.UpdateLocation)
		rg.Post("/locations/kml", location.ConvertKML)
		rg.Post("/locations", location.ExcludeLocation)

		// Message List (moderation queue + public listing)
		// @Router /messages [get]
		// @Summary List messages with moderation queue support
		// @Tags message
		rg.Get("/messages", deprecation.Marker("GET /messages", "2026-08-01"), message.ListMessages)
		rg.Get("/modtools/messages", message.ListMessagesMT)

		// Message Sitemap
		// @Router /message/sitemap [get]
		// @Summary Live posts for the search-engine sitemap
		// @Description Returns id + lastmod for every currently-live Offer/Wanted post, for building sitemap.xml
		// @Tags message
		// @Produce json
		// @Success 200 {array} message.SitemapEntry
		rg.Get("/message/sitemap", message.Sitemap)

		// Message Count
		// @Router /message/count [get]
		// @Summary Get message count
		// @Description Returns count of messages by type
		// @Tags message
		// @Produce json
		// @Success 200 {object} isochrone.CountResult
		rg.Get("/message/count", isochrone.Count)

		// Message Bounds
		// @Router /message/inbounds [get]
		// @Summary Get messages in bounds
		// @Description Returns messages within geographic bounds
		// @Tags message
		// @Produce json
		// @Param swlat query number true "Southwest latitude"
		// @Param swlng query number true "Southwest longitude"
		// @Param nelat query number true "Northeast latitude"
		// @Param nelng query number true "Northeast longitude"
		// @Success 200 {array} message.Message
		rg.Get("/message/inbounds", message.Bounds)

		// Messages by Group
		// @Router /message/mygroups/{id} [get]
		// @Summary Get messages by group
		// @Description Returns messages for user's groups, optionally filtered by group ID
		// @Tags message,group
		// @Produce json
		// @Param id path integer false "Group ID (optional)"
		// @Security BearerAuth
		// @Success 200 {array} message.Message
		rg.Get("/message/mygroups/:id?", message.Groups)

		// Message Search
		// @Router /message/search/{term} [get]
		// @Summary Search messages
		// @Description Searches messages by term
		// @Tags message
		// @Produce json
		// @Param term path string true "Search term"
		// @Param messagetype query string false "Message type filter"
		// @Param groupids query string false "Group IDs to filter by (comma separated)"
		// @Success 200 {array} message.SearchResult
		rg.Get("/message/search/:term", message.Search)

		// Messages by ID
		// @Router /message/{ids} [get]
		// @Summary Get messages by ID
		// @Description Returns messages by ID (comma separated)
		// @Tags message
		// @Produce json
		// @Param ids path string true "Message IDs (comma separated)"
		// @Success 200 {array} message.Message
		// @Failure 404 {object} fiber.Error "Message not found"
		// Actual rippling-out progress of a post, for the moderation reach map to compare
		// against the expected/projected reach. Mod-of-group only.
		// @Router /message/{id}/reach [get]
		// @Summary Actual rippling-out progress of a post (moderation)
		// @Tags message
		// @Produce json
		// @Param id path int true "Message ID"
		// @Success 200 {object} message.ReachResponse
		// @Failure 403 {object} fiber.Error "Moderator of the post's group required"
		rg.Get("/message/:id/reach", message.Reach)

		// Similar posts for the "more like this nearby" recommendation strip.
		// @Router /message/{id}/similar [get]
		// @Summary Posts similar to a given post (recommendations)
		// @Tags message
		// @Produce json
		// @Param id path int true "Message ID"
		// @Param limit query int false "Max results (default 8, max 20)"
		// @Success 200 {array} message.SimilarResult
		rg.Get("/message/:id/similar", message.Similar)

		// Offers matching a wanted being composed. Registered before /message/:ids
		// so "matches" is not treated as a message id.
		// @Router /message/matches [get]
		// @Summary Offers matching a wanted being composed (recommendations)
		// @Tags message
		// @Produce json
		// @Param query query string true "Item text of the wanted"
		// @Param lat query number true "Poster's latitude"
		// @Param lng query number true "Poster's longitude"
		// @Success 200 {array} message.SimilarResult
		rg.Get("/message/matches", message.Matches)

		// Opposite-type posts matching a given post — candidate set for the
		// matched-posts email (batch job). Reach-filtered against the post owner.
		// @Router /message/{id}/matches [get]
		// @Summary Opposite-type posts matching a given post (matched-posts email)
		// @Tags message
		// @Produce json
		// @Param id path int true "Message ID"
		// @Param limit query int false "Max results (default 10, max 30)"
		// @Success 200 {array} message.SimilarResult
		rg.Get("/message/:id/matches", message.PostMatches)

		rg.Get("/message/:ids", message.GetMessagesWithHistory)

		// Mark Messages Seen
		// @Router /messages/markseen [post]
		// @Summary Mark messages as seen
		// @Description Records that the user has viewed the specified messages
		// @Tags message
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		// @Failure 400 {object} fiber.Error "Invalid request"
		// @Failure 401 {object} fiber.Error "Not logged in"
		rg.Post("/messages/markseen", message.MarkSeen)

		// Message Actions (POST)
		// @Router /message [post]
		// @Summary Message actions
		// @Description Handles message actions: Promise, Renege, OutcomeIntended, Outcome, AddBy, RemoveBy, View, Approve, Reject, Delete, Spam, Hold, Release, ApproveEdits, RevertEdits, PartnerConsent, Reply, JoinAndPost, Move, BackToPending, RejectToDraft. When tnpostid is supplied instead of id, the action is applied to ALL Freegle messages sharing that TN post ID.
		// @Tags message
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/message", message.PostMessage)
		rg.Patch("/message", message.PatchMessage)

		// @Router /message/tn/{tnpostid} [patch]
		// @Summary Update all messages by TN post ID
		// @Description Edit ALL Freegle messages that share the given Trash Nothing post ID. Used by partner integrations when a TN post is submitted to multiple Freegle groups.
		// @Tags message
		// @Accept json
		// @Produce json
		// @Param tnpostid path string true "Trash Nothing post ID"
		// @Success 200 {object} map[string]interface{}
		rg.Patch("/message/tn/:tnpostid", message.PatchMessageByTN)
		rg.Put("/message", message.PutMessage)
		rg.Delete("/message/:id", deprecation.Marker("DELETE /message/:id", "2026-08-01"), message.DeleteMessageEndpoint)

		// Bulk-offer ("clearance") logged-out update page: an external item-owner
		// toggles item available/taken and edits counts via an unguessable secret
		// token in the URL. No JWT - the token is the sole credential and grants
		// only availability/count edits to that one offer (see message/bulkEdit.go).
		rg.Get("/bulkoffer/update/:token", message.GetBulkEditOffer)
		rg.Post("/bulkoffer/update/:token", message.PostBulkEditOffer)

		// Freegle Helper — cross-clearance escalated queue (ModTools). Registered
		// before /helper/:msgid so the literal "escalated" isn't parsed as a msgid.
		rg.Get("/helper/escalated", message.GetHelperEscalated)

		// Freegle Helper — AI concierge state + proposals for a bulk offer.
		// @Router /helper/{msgid} [get]
		// @Summary Get Helper state for a bulk offer
		// @Description Offerer/mod only. Returns the Helper batch, per-replier FSM knowledge records with per-item state and score, queued proposals, and Helper-sent message ids.
		// @Tags message
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Get("/helper/:msgid", message.GetHelper)

		// @Router /helper [post]
		// @Summary Helper actions
		// @Description Offerer/mod only. Actions: EnsureBatch, SetStatus (pause/resume/stop), UpsertReplier, SetItemState, Proposal, ResolveProposal (confirm/edit/send or dismiss), Send (auto-send a conversational message).
		// @Tags message
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/helper", message.PostHelper)

		// User
		// @Router /user/{id} [get]
		// @Summary Get user by ID
		// @Description Returns a single user by ID, or the current user if no ID
		// @Tags user
		// @Produce json
		// @Param id path integer false "User ID (optional)"
		// @Security BearerAuth
		// @Success 200 {object} user.User
		// @Failure 404 {object} fiber.Error "User not found"
		rg.Get("/user/search", user.SearchUsers)
		rg.Get("/user/byemail/:email", user.GetUserByEmail)
		// Targeted opt-out from matched-posts suggestion emails (relevantallowed=0),
		// key-authenticated so it works as a one-click List-Unsubscribe. Registered
		// before /user/:id? so "relevantoff" is not treated as a user id.
		// @Router /user/relevantoff [get]
		rg.Get("/user/relevantoff", user.RelevantOff)
		rg.Post("/user/relevantoff", user.RelevantOff)
		// Category opt-out, the HTTPS arm of the List-Unsubscribe header on bulk mail.
		// Key-authenticated like relevantoff, and registered before /user/:id? for the
		// same reason: so "unsubscribe" is not treated as a user id.
		// @Router /user/unsubscribe [post]
		rg.Get("/user/unsubscribe", user.Unsubscribe)
		rg.Post("/user/unsubscribe", user.Unsubscribe)
		rg.Get("/user/:id?", user.GetUser)

		// User Actions (POST)
		// @Router /user [post]
		// @Summary User actions
		// @Description Handles user actions: Rate, RatingReviewed, AddEmail, RemoveEmail, Engaged
		// @Tags user
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Post("/user", user.PostUser)
		rg.Put("/user", user.PutUser)
		rg.Patch("/user", user.PatchUser)
		rg.Delete("/user", user.LimboUser)

		// User Public Location
		// @Router /user/{id}/publiclocation [get]
		// @Summary Get user's public location
		// @Description Returns the public location for a specific user
		// @Tags user
		// @Produce json
		// @Param id path integer true "User ID"
		// @Success 200 {object} location.Location
		rg.Get("/user/:id/publiclocation", user.GetPublicLocation)

		// User Messages
		// @Router /user/{id}/message [get]
		// @Summary Get messages for user
		// @Description Returns messages created by a specific user
		// @Tags user,message
		// @Produce json
		// @Param id path integer true "User ID"
		// @Param active query boolean false "Only show active messages"
		// @Success 200 {array} message.MessageSummary
		rg.Get("/user/:id/message", message.GetMessagesForUser)

		// User Searches
		// @Router /user/{id}/search [get]
		// @Summary Get searches for user
		// @Description Returns saved searches for a specific user
		// @Tags user
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} user.Search
		rg.Get("/user/:id/search", user.GetSearchesForUser)

		// Delete User Search
		// @Router /usersearch [delete]
		// @Summary Delete a user search
		// @Description Soft-deletes a user search (sets deleted=1)
		// @Tags usersearch
		// @Produce json
		// @Param id query integer true "Search ID"
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Delete("/usersearch", user.DeleteUserSearch)

		// Newsfeed Item
		// @Router /newsfeed/{id} [get]
		// @Summary Get newsfeed item by ID
		// @Description Returns a single newsfeed item by ID
		// @Tags newsfeed
		// @Produce json
		// @Param id path integer true "Newsfeed ID"
		// @Success 200 {object} newsfeed.Item
		// @Failure 404 {object} fiber.Error "Newsfeed item not found"
		rg.Get("/newsfeed/:id", newsfeed.Single)

		// Newsfeed duplicate check (ChitChat moderators only)
		// @Router /newsfeed/{id}/duplicate [get]
		// @Summary Whether a ChitChat post duplicates the poster's own live OFFER/WANTED
		// @Description Moderator-only. Names one of the poster's own live posts when the
		// @Description ChitChat entry says the same thing, so it can be hidden.
		// @Tags newsfeed
		// @Produce json
		// @Param id path integer true "Newsfeed ID"
		// @Success 200 {object} newsfeed.DuplicateResponse
		// @Failure 403 {object} fiber.Error "Not a ChitChat moderator"
		rg.Get("/newsfeed/:id/duplicate", newsfeed.Duplicate)

		// @Router /newsfeed/{id}/convertinfo [get]
		// @Summary Where a convert-to-post would land
		// @Description Moderator-only. The postcode and community a post made for the
		// @Description member would use, so the modal can show it before committing.
		// @Tags newsfeed
		// @Produce json
		// @Param id path integer true "Newsfeed ID"
		// @Success 200 {object} newsfeed.ConvertInfoResult
		// @Failure 403 {object} fiber.Error "Not a ChitChat moderator"
		rg.Get("/newsfeed/:id/convertinfo", newsfeed.ConvertInfo)

		// Newsfeed Count
		// @Router /newsfeedcount [get]
		// @Summary Get newsfeed count
		// @Description Returns count of newsfeed items
		// @Tags newsfeed
		// @Produce json
		// @Success 200 {object} newsfeed.CountResult
		rg.Get("/newsfeedcount", newsfeed.Count)

		// Newsfeed
		// @Router /newsfeed [get]
		// @Summary Get newsfeed
		// @Description Returns newsfeed items
		// @Tags newsfeed
		// @Produce json
		// @Success 200 {array} newsfeed.Item
		rg.Get("/newsfeed", newsfeed.Feed)
		rg.Post("/newsfeed", newsfeed.Post)
		rg.Patch("/newsfeed", newsfeed.Edit)
		rg.Delete("/newsfeed/:id", newsfeed.Delete)

		// Notification Count
		// @Router /notification/count [get]
		// @Summary Get notification count
		// @Description Returns count of notifications
		// @Tags notification
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} notification.CountResult
		rg.Get("/notification/count", notification.Count)

		// Notifications
		// @Router /notification [get]
		// @Summary List notifications
		// @Description Returns all notifications for the authenticated user
		// @Tags notification
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {array} notification.Notification
		rg.Get("/notification", notification.List)

		// Online Status
		// @Router /online [get]
		// @Summary Check online status
		// @Description Returns online status information
		// @Tags misc
		// @Produce json
		// @Success 200 {object} misc.OnlineResult
		rg.Get("/online", misc.Online)

		// Stories
		// @Router /story [get]
		// @Summary List stories
		// @Description Returns all stories
		// @Tags story
		// @Produce json
		// @Success 200 {array} story.Story
		rg.Get("/story", story.List)

		// Single Story
		// @Router /story/{id} [get]
		// @Summary Get story by ID
		// @Description Returns a single story by ID
		// @Tags story
		// @Produce json
		// @Param id path integer true "Story ID"
		// @Success 200 {object} story.Story
		// @Failure 404 {object} fiber.Error "Story not found"
		rg.Get("/story/:id", story.Single)

		// Group Stories
		// @Router /story/group/{id} [get]
		// @Summary Get stories for group
		// @Description Returns stories for a specific group
		// @Tags story,group
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {array} story.Story
		rg.Get("/story/group/:id", story.Group)

		// Story Write Operations
		// @Router /story [put]
		// @Summary Create a story
		// @Tags story
		// @Accept json
		// @Produce json
		rg.Put("/story", story.CreateStory)

		// @Router /story [patch]
		// @Summary Update a story (mod review)
		// @Tags story
		// @Accept json
		// @Produce json
		rg.Patch("/story", story.UpdateStory)

		// @Router /story [post]
		// @Summary Story actions (Like/Unlike)
		// @Tags story
		// @Accept json
		// @Produce json
		rg.Post("/story", deprecation.Marker("POST /story", "2026-08-01"), story.PostStory)

		// @Router /story/like [post]
		// @Summary Like a story
		// @Tags story
		// @Accept json
		// @Produce json
		rg.Post("/story/like", story.LikeStory)

		// @Router /story/unlike [post]
		// @Summary Unlike a story
		// @Tags story
		// @Accept json
		// @Produce json
		rg.Post("/story/unlike", story.UnlikeStory)

		// @Router /story/{id} [delete]
		// @Summary Delete a story
		// @Tags story
		// @Param id path integer true "Story ID"
		// @Produce json
		rg.Delete("/story/:id", deprecation.Marker("DELETE /story/:id", "2026-08-01"), story.DeleteStory)

		// Session Actions
		// @Router /session [post]
		// @Summary Session actions (LostPassword, Unsubscribe)
		// @Description Dispatches session write actions based on "action" parameter
		// @Tags session
		// @Accept json
		// @Produce json
		// @Param body body object true "Action and email"
		// @Success 200 {object} map[string]interface{}
		rg.Post("/session", session.PostSession)
		rg.Get("/session", session.GetSession)
		rg.Patch("/session", session.PatchSession)
		rg.Delete("/session", session.DeleteSession)

		// Shortlinks
		// @Router /shortlink [get]
		// @Summary Get shortlinks
		// @Description Returns a single shortlink by ID or lists all shortlinks
		// @Tags shortlink
		// @Produce json
		// @Param id query integer false "Shortlink ID"
		// @Param groupid query integer false "Filter by group ID"
		// @Success 200 {object} map[string]interface{}
		rg.Get("/shortlink", shortlink.GetShortlink)

		// Create Shortlink
		// @Router /shortlink [post]
		// @Summary Create a shortlink
		// @Tags shortlink
		// @Accept json
		// @Produce json
		rg.Post("/shortlink", shortlink.PostShortlink)

		// System Status
		// @Router /status [get]
		// @Summary Get system status
		// @Description Returns the system status from /tmp/iznik.status
		// @Tags status
		// @Produce json
		// @Success 200 {object} map[string]interface{}
		rg.Get("/status", status.GetStatus)
		rg.Get("/version", status.GetVersion)

		// Logs
		rg.Get("/modtools/logs", logs.GetLogs)

		// Spammers
		rg.Get("/modtools/spammers", spammers.GetSpammers)
		rg.Get("/modtools/spammers/export", spammers.ExportSpammers)
		rg.Post("/modtools/spammers", spammers.PostSpammer)
		rg.Patch("/modtools/spammers", spammers.PatchSpammer)
		rg.Delete("/modtools/spammers", spammers.DeleteSpammer)

		// Teams
		rg.Get("/team", team.GetTeam)
		rg.Post("/team", deprecation.Marker("POST /team", "2026-08-01"), team.PostTeam)
		rg.Patch("/team", team.PatchTeam)
		rg.Delete("/team", deprecation.Marker("DELETE /team", "2026-08-01"), team.DeleteTeam)

		// Mod Configs
		rg.Get("/modtools/modconfig", modconfig.GetModConfig)
		rg.Post("/modtools/modconfig", modconfig.PostModConfig)
		rg.Patch("/modtools/modconfig", modconfig.PatchModConfig)
		rg.Delete("/modtools/modconfig", modconfig.DeleteModConfig)

		// Standard Messages
		rg.Get("/modtools/stdmsg", stdmsg.GetStdMsg)
		rg.Post("/modtools/stdmsg", stdmsg.PostStdMsg)
		rg.Patch("/modtools/stdmsg", stdmsg.PatchStdMsg)
		rg.Delete("/modtools/stdmsg", stdmsg.DeleteStdMsg)

		// Trysts (handover arrangements)
		rg.Get("/tryst", tryst.GetTryst)
		rg.Put("/tryst", tryst.CreateTryst)
		rg.Post("/tryst", tryst.PostTryst)
		rg.Patch("/tryst", tryst.PatchTryst)
		rg.Delete("/tryst", tryst.DeleteTryst)

		// Volunteering Opportunities
		// @Router /volunteering [get]
		// @Summary List volunteering opportunities
		// @Description Returns all volunteering opportunities
		// @Tags volunteering
		// @Produce json
		// @Success 200 {array} volunteering.Volunteering
		rg.Get("/volunteering", volunteering.List)

		// Group Volunteering Opportunities
		// @Router /volunteering/group/{id} [get]
		// @Summary List volunteering opportunities for group
		// @Description Returns volunteering opportunities for a specific group
		// @Tags volunteering,group
		// @Produce json
		// @Param id path integer true "Group ID"
		// @Success 200 {array} volunteering.Volunteering
		rg.Get("/volunteering/group/:id", volunteering.ListGroup)

		// Single Volunteering Opportunity
		// @Router /volunteering/{id} [get]
		// @Summary Get volunteering opportunity by ID
		// @Description Returns a single volunteering opportunity by ID
		// @Tags volunteering
		// @Produce json
		// @Param id path integer true "Volunteering ID"
		// @Success 200 {object} volunteering.Volunteering
		// @Failure 404 {object} fiber.Error "Volunteering opportunity not found"
		rg.Get("/volunteering/:id", volunteering.Single)

		// Visualise
		// @Router /visualise [get]
		// @Summary Get visualisation data
		// @Description Returns items given/taken with locations and user icons for homepage map
		// @Tags visualise
		// @Produce json
		// @Param swlat query number true "Southwest latitude"
		// @Param swlng query number true "Southwest longitude"
		// @Param nelat query number true "Northeast latitude"
		// @Param nelng query number true "Northeast longitude"
		// @Param limit query integer false "Max results (default 5)"
		// @Param context query integer false "Pagination cursor"
		// @Success 200 {object} map[string]interface{}
		rg.Get("/visualise", visualise.GetVisualise)

		// Email Statistics (authenticated, admin only)
		// @Router /email/stats [get]
		// @Summary Get email tracking statistics
		// @Description Returns aggregate email statistics for Support/Admin users
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param type query string false "Email type filter"
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats", emailtracking.Stats)

		// Email Statistics Time Series (authenticated, admin only)
		// @Router /email/stats/timeseries [get]
		// @Summary Get daily email statistics for charting
		// @Description Returns daily sent/opened/clicked/bounced counts for date range
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param type query string false "Email type filter"
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats/timeseries", emailtracking.TimeSeries)

		// Email Statistics By Type (authenticated, admin only)
		// @Router /email/stats/bytype [get]
		// @Summary Get email statistics by email type
		// @Description Returns statistics for each email type for comparison charts
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats/bytype", emailtracking.StatsByType)

		// Top Clicked Links (authenticated, admin only)
		// @Router /email/stats/clicks [get]
		// @Summary Get top clicked links from emails
		// @Description Returns the most clicked links, normalized to remove user-specific data
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Param limit query int false "Number of links to return (default 5, use 0 for all)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats/clicks", emailtracking.TopClickedLinks)

		// Digest Click Positions (authenticated, admin only)
		// @Router /email/stats/digestpositions [get]
		// @Summary Get digest click-through rate by post position
		// @Description Returns click-through rate per post position within unified digests, for analysing how position affects engagement
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Param type query string false "Email type filter (default: all UnifiedDigest* types)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats/digestpositions", emailtracking.DigestClickPositions)

		// Re-engagement Email Effectiveness (authenticated, admin only)
		// @Router /email/stats/reengage [get]
		// @Summary Get re-engagement email effectiveness
		// @Description Returns funnel (sent/opened/clicked/reengaged) counts overall and broken down by stage, experiment arm and journey segment
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param start query string false "Start date (YYYY-MM-DD)"
		// @Param end query string false "End date (YYYY-MM-DD)"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/stats/reengage", emailtracking.ReengageEffectiveness)

		// Browse-feed scroll-depth curve for the sysadmin "Scrolling" tab (Support/Admin).
		// @Router /modtools/scroll/depth [get]
		// @Summary Browse-feed scroll-depth curve
		// @Description For each feed position N, the fraction of sessions that scrolled at least N deep
		// @Tags browse
		// @Produce json
		rg.Get("/modtools/scroll/depth", browse.ScrollDepthCurve)

		// Recommendation funnel (impressions/clicks/replies + holdout) for the
		// sysadmin "Recommendations" tab (Support/Admin).
		// @Router /modtools/recommendations/stats [get]
		// @Summary Recommendation funnel stats
		// @Tags recommendations
		// @Produce json
		rg.Get("/modtools/recommendations/stats", recommendations.Stats)

		// Email Tracking for specific user (authenticated, admin only)
		// @Router /email/user/{id} [get]
		// @Summary Get email tracking for a user
		// @Description Returns email tracking records for a specific user (Support/Admin only)
		// @Tags emailtracking
		// @Produce json
		// @Security BearerAuth
		// @Param id path int true "User ID"
		// @Param limit query int false "Number of records (default 50)"
		// @Param offset query int false "Offset for pagination"
		// @Success 200 {object} map[string]interface{}
		// @Failure 401 {object} fiber.Error "Unauthorized"
		// @Failure 403 {object} fiber.Error "Forbidden"
		rg.Get("/modtools/email/user/:id", emailtracking.UserEmails)

		// Donations
		// @Router /donations [get]
		// @Summary Get donations
		// @Description Returns donation information
		// @Tags donations
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} donations.DonationsResponse
		rg.Get("/donations", donations.GetDonations)

		// @Router /donations [put]
		// @Summary Record external donation
		// @Description Records an external bank transfer donation
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Put("/donations", donations.AddDonation)
		rg.Post("/donations/bulk", donations.BulkUploadDonations)

		// @Router /stripecreateintent [post]
		// @Summary Create Stripe PaymentIntent
		// @Description Creates a Stripe PaymentIntent for a one-time donation
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Success 200 {object} map[string]interface{}
		rg.Post("/stripecreateintent", donations.CreateIntent)

		// @Router /stripecreatesubscription [post]
		// @Summary Create Stripe subscription
		// @Description Creates a Stripe subscription for recurring monthly donation
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/stripecreatesubscription", donations.CreateSubscription)

		// Stripe webhook (IPN) — called by Stripe when charges succeed.
		// @Router /stripeipn [post]
		// @Summary Handle Stripe webhook
		// @Description Processes Stripe charge.succeeded events, records donations, handles gift aid
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Success 200
		rg.Post("/stripeipn", donations.StripeIPN)

		// Gift Aid
		// @Router /giftaid [get]
		// @Summary Get Gift Aid declaration
		// @Description Returns user's Gift Aid declaration. With all=true returns admin review list. With search=xxx searches records.
		// @Tags donations
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} donations.GiftAid
		rg.Get("/giftaid", donations.GetGiftAid)

		// @Router /giftaid [post]
		// @Summary Set Gift Aid declaration
		// @Description Creates or updates the user's Gift Aid declaration
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/giftaid", donations.SetGiftAid)

		// @Router /giftaid [patch]
		// @Summary Edit Gift Aid declaration (admin)
		// @Description Admin edits a Gift Aid record
		// @Tags donations
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Patch("/giftaid", donations.EditGiftAid)

		// @Router /giftaid [delete]
		// @Summary Delete Gift Aid declaration
		// @Description Soft-deletes the user's Gift Aid declaration
		// @Tags donations
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Delete("/giftaid", donations.DeleteGiftAid)

		// Housekeeper — receives task results from Chrome extension
		// @Router /housekeeper/notify [post]
		// @Summary Receive housekeeping task result
		// @Description Queues a background task to process housekeeping results (e.g. Facebook deletion IDs)
		// @Tags housekeeper
		// @Accept json
		// @Produce json
		// @Success 200
		rg.Post("/housekeeper/notify", housekeeper.Notify)
		rg.Get("/housekeeper/tasks", housekeeper.ListTasks)
		rg.Post("/housekeeper/tasks/:key/complete", housekeeper.CompleteTask)
		rg.Get("/housekeeper/cronjobs", housekeeper.ListCronJobs)

		// GDPR Data Export
		rg.Post("/export", export.PostExport)
		rg.Get("/export", export.GetExport)

		// Microvolunteering
		// @Router /microvolunteering [get]
		// @Summary Get microvolunteering challenge
		// @Description Returns a microvolunteering challenge
		// @Tags microvolunteering
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} microvolunteering.Challenge
		rg.Get("/microvolunteering", microvolunteering.GetChallenge)

		// Microvolunteering POST
		// @Router /microvolunteering [post]
		// @Summary Submit micro-volunteering response
		// @Description Records the user's response to a micro-volunteering challenge
		// @Tags microvolunteering
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Post("/microvolunteering", microvolunteering.PostResponse)

		// Microvolunteering PATCH (mod feedback)
		// @Router /microvolunteering [patch]
		// @Summary Provide moderator feedback on microaction
		// @Description Allows a moderator to set feedback and scores on a microaction
		// @Tags microvolunteering
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Patch("/microvolunteering", deprecation.Marker("PATCH /microvolunteering", "2026-08-01"), microvolunteering.ModFeedback)

		// User by Email

		// Support tools: per-user data endpoints (mod-only).
		// Caller must be a moderator of a group the target user belongs to.

		// @Router /user/{id}/chatrooms [get]
		// @Summary Get chat rooms for a user
		// @Description Returns chat rooms where the target user is a participant. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/chatrooms", user.GetUserChatrooms)

		// @Router /user/{id}/emailhistory [get]
		// @Summary Get email history for a user
		// @Description Returns recent emails sent to/from the user from logs_emails. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/emailhistory", user.GetUserEmailHistory)

		// @Router /user/{id}/bans [get]
		// @Summary Get ban records for a user
		// @Description Returns groups the user has been banned from. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/bans", user.GetUserBans)

		// @Router /user/{id}/newsfeed [get]
		// @Summary Get ChitChat posts for a user
		// @Description Returns newsfeed/ChitChat posts by the user. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/newsfeed", user.GetUserNewsfeed)

		// @Router /user/{id}/applied [get]
		// @Summary Get recent group applications for a user
		// @Description Returns groups the user applied to in the last 31 days. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/applied", user.GetUserApplied)

		// @Router /user/{id}/replies [get]
		// @Summary Get messages a user replied to
		// @Description Returns messages the user expressed interest in. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Param type query string false "Filter by message type (Offer/Wanted)"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/replies", user.GetUserReplies)

		// @Router /user/{id}/membershiphistory [get]
		// @Summary Get full membership history for a user
		// @Description Returns all membership changes (joins/leaves) for the user. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Param limit query integer false "Max records (default 100, max 500)"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/membershiphistory", user.GetUserMembershipHistory)

		// @Router /user/{id}/logins [get]
		// @Summary Get login history for a user
		// @Description Returns login methods and last access times. Mod-only.
		// @Tags user-support
		// @Produce json
		// @Param id path integer true "User ID"
		// @Security BearerAuth
		// @Success 200 {array} object
		rg.Get("/user/:id/logins", user.GetUserLogins)

		// Mark Notification Seen
		// @Router /notification/seen [post]
		// @Summary Mark notification as seen
		// @Description Marks a specific notification as seen
		// @Tags notification
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/notification/seen", notification.Seen)

		// Mark All Notifications Seen
		// @Router /notification/allseen [post]
		// @Summary Mark all notifications as seen
		// @Description Marks all notifications as seen for the user
		// @Tags notification
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} map[string]interface{}
		rg.Post("/notification/allseen", notification.AllSeen)

		// Latest Message
		// @Router /latestmessage [get]
		// @Summary Get latest message timestamp
		// @Description Returns the timestamp of the latest message
		// @Tags message
		// @Produce json
		// @Success 200 {object} misc.LatestMessageResponse
		rg.Get("/latestmessage", misc.LatestMessage)

		// AI Illustration
		// @Router /illustration [get]
		// @Summary Get AI illustration for item
		// @Description Returns a cached AI-generated illustration for an item name. Returns ret=3 if not cached.
		// @Tags misc
		// @Produce json
		// @Param item query string true "Item name"
		// @Success 200 {object} misc.IllustrationResult
		rg.Get("/illustration", misc.GetIllustration)

		// Source Tracking
		// @Router /src [post]
		// @Summary Record source tracking
		// @Description Records source tracking data for analytics
		// @Tags misc
		// @Accept json
		// @Produce json
		// @Param source body src.SourceRequest true "Source tracking data"
		// @Success 200 {object} map[string]interface{}
		rg.Post("/src", src.RecordSource)

		// Memberships
		// @Router /memberships [put]
		// @Summary Subscribe user to group
		// @Description Adds a user to a group. Supports JWT auth (self-join or mod-add-member) and partner key auth (TN integration).
		// @Tags membership
		// @Accept json
		// @Produce json
		// @Param partner query string false "Partner API key (alternative to JWT auth)"
		// @Param tnuserid query integer false "Trash Nothing user ID (partner auth only)"
		// @Param email query string false "User email address (partner auth only)"
		// @Param groupid query integer false "Group ID (partner auth only; JWT auth uses body)"
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map "Returns ret, status, addedto, and fduserid (partner auth)"
		rg.Put("/memberships", membership.PutMemberships)

		// @Router /memberships [delete]
		// @Summary Unsubscribe user from group
		// @Description Removes a user from a group. Supports JWT auth and partner key auth (TN integration).
		// @Tags membership
		// @Accept json
		// @Produce json
		// @Param partner query string false "Partner API key (alternative to JWT auth)"
		// @Param tnuserid query integer false "Trash Nothing user ID (partner auth only)"
		// @Param email query string false "User email address (partner auth only)"
		// @Param groupid query integer false "Group ID (partner auth only; JWT auth uses body)"
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map "Returns ret, status, and fduserid (partner auth)"
		rg.Delete("/memberships", membership.DeleteMemberships)

		// @Router /memberships [patch]
		// @Summary Update membership settings
		// @Description Updates email frequency, events allowed, volunteering allowed
		// @Tags membership
		// @Accept json
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} fiber.Map
		rg.Patch("/memberships", membership.PatchMemberships)
		rg.Get("/memberships", membership.GetMemberships)
		rg.Post("/memberships", membership.PostMemberships)

		// Merge
		rg.Get("/merge", merge.GetMerge)
		rg.Put("/merge", merge.CreateMerge)
		rg.Post("/merge", merge.PostMerge)
		rg.Delete("/merge", merge.DeleteMerge)

		// Simulation
		rg.Get("/simulation", deprecation.Marker("GET /simulation", "2026-08-01"), simulation.GetSimulation)

		// Domains
		rg.Get("/domains", domain.GetDomain)

		// System Logs (moderator only)
		systemLogsGroup := rg.Group("/modtools/systemlogs")
		systemLogsGroup.Use(systemlogs.RequireModeratorMiddleware())
		// @Router /systemlogs [get]
		// @Summary Get system logs
		// @Description Returns system logs (moderator only)
		// @Tags systemlogs
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} systemlogs.LogsResponse
		systemLogsGroup.Get("", systemlogs.GetLogs)
		// @Router /systemlogs/counts [get]
		// @Summary Get log counts by subtype
		// @Description Returns counts of logs grouped by subtype using Loki metric queries (moderator only)
		// @Tags systemlogs
		// @Produce json
		// @Security BearerAuth
		// @Success 200 {object} systemlogs.CountsResponse
		systemLogsGroup.Get("/counts", systemlogs.GetLogCounts)

		// User support data dump (Support/Admin only) — streams a per-user SQLite
		// database of every user-linked table plus their Loki logs and Sentry issues.
		rg.Get("/modtools/user/:id/dump", userdump.GetUserDump)
	}

	// Delivery routes (public - no auth required for email client access)
	// Using bland paths to avoid privacy blocker detection
	delivery := app.Group("/e/d")

	// Pixel - returns 1x1 transparent GIF
	// @Router /e/d/p/{id} [get]
	// @Summary Delivery pixel
	// @Description Returns 1x1 transparent GIF
	// @Tags delivery
	// @Produce image/gif
	// @Param id path string true "ID"
	// @Success 200 {file} file
	delivery.Get("/p/:id", emailtracking.Pixel)

	// Redirect - handles link clicks and button actions
	// @Router /e/d/r/{id} [get]
	// @Summary Delivery redirect
	// @Description Redirects to destination URL
	// @Tags delivery
	// @Param id path string true "ID"
	// @Param url query string true "Base64 encoded destination URL"
	// @Param p query string false "Position"
	// @Param a query string false "Action type"
	// @Success 302 {string} string "Redirect"
	delivery.Get("/r/:id", emailtracking.Click)

	// Image - handles image loads for scroll depth
	// @Router /e/d/i/{id} [get]
	// @Summary Delivery image
	// @Description Redirects to original image
	// @Tags delivery
	// @Param id path string true "ID"
	// @Param url query string true "Base64 encoded image URL"
	// @Param p query string true "Position"
	// @Param s query integer false "Scroll percentage"
	// @Success 302 {string} string "Redirect"
	delivery.Get("/i/:id", emailtracking.Image)

	// Compact redirect — reconstructs an internal destination from type+id.
	// MORE path segments than /r/:id so Fiber matches it as a distinct route.
	// @Router /e/d/r/{ref}/{type}/{idenc}/{pos} [get]
	// @Summary Compact delivery redirect
	// @Description Reconstructs an internal destination URL from type+id and redirects
	// @Tags delivery
	// @Param ref path string true "12-char tracking ref"
	// @Param type path string true "Resource type (m, s, g)"
	// @Param idenc path string true "base64url-encoded resource id"
	// @Param pos path string true "Position label"
	// @Success 302 {string} string "Redirect"
	delivery.Get("/r/:ref/:type/:idenc/:pos", emailtracking.ClickCompact)

	// Compact image — reconstructs a delivery URL from type+id+preset.
	// MORE path segments than /i/:id so Fiber matches it as a distinct route.
	// @Router /e/d/i/{ref}/{type}/{idenc}/{preset}/{pos} [get]
	// @Summary Compact delivery image
	// @Description Reconstructs an image delivery URL from type+id+preset and redirects
	// @Tags delivery
	// @Param ref path string true "12-char tracking ref"
	// @Param type path string true "Resource type (t, u)"
	// @Param idenc path string true "base64url-encoded resource id"
	// @Param preset path int true "Dimension preset (0,1,2)"
	// @Param pos path string true "Position label"
	// @Success 302 {string} string "Redirect to image"
	delivery.Get("/i/:ref/:type/:idenc/:preset/:pos", emailtracking.ImageCompact)

	// Note: MDN read receipts come as emails and are processed by the incoming mail handler.
	// The emailtracking.RecordMDNOpen() function can be called via internal API.

	// AMP Email endpoints (public - token authenticated)
	// Shortlink redirect — public-facing redirect endpoint (separate from API /shortlink).
	// V1 equivalent: http/shortlink.php
	// @Router /shortlink [get]
	// @Summary Redirect shortlink
	// @Description Resolves a shortlink name and redirects to the target URL
	// @Tags shortlink
	// @Param name query string false "Shortlink name"
	// @Success 302
	app.Get("/shortlink", shortlink.RedirectShortlink)

	// Avatar image — generates a deterministic geometric PNG from a name string.
	// Identical algorithm to the frontend GeneratedAvatar.client.vue component,
	// replacing the Node.js avatar-server container.
	// @Router /avatar/{name} [get]
	// @Summary Generate avatar PNG
	// @Tags avatar
	// @Param name path string true "User name (append .png for explicit PNG extension)"
	// @Param size query integer false "Pixel size, max 256 (default 48)"
	// @Produce image/png
	// @Success 200
	app.Get("/avatar/:name", avatar.GetAvatar)

	// PayPal IPN — called by PayPal when donations are received.
	// V1 equivalent: http/donateipn.php
	// @Router /donateipn [post]
	// @Summary Handle PayPal IPN
	// @Description Processes PayPal donation notifications, records donations, handles gift aid
	// @Tags donations
	// @Accept application/x-www-form-urlencoded
	// @Produce json
	// @Success 200
	app.Post("/donateipn", donations.PayPalIPN)

	// Discourse SSO — validates moderator session and redirects to Discourse with signed SSO response.
	// V1 equivalent: http/discourse_sso.php
	// @Router /discourse_sso [get]
	// @Summary Discourse SSO login
	// @Description Validates moderator session and redirects to Discourse with signed SSO response
	// @Tags sso
	// @Param sso query string true "Base64-encoded SSO payload"
	// @Param sig query string true "HMAC-SHA256 signature"
	// @Success 302
	app.Get("/discourse_sso", sso.DiscourseSSO)

	// These endpoints support AMP for Email dynamic content and inline actions.
	// See: https://amp.dev/documentation/guides-and-tutorials/learn/cors-in-email
	ampGroup := app.Group("/amp")
	ampGroup.Use(amp.AMPCORSMiddleware())

	// Get chat messages for AMP email
	// @Router /amp/chat/{id} [get]
	// @Summary Get chat messages for AMP email
	// @Description Returns the last 5 chat messages for the "Earlier conversation" section
	// @Tags AMP
	// @Produce json
	// @Param id path int true "Chat ID"
	// @Param rt query string true "Read token (HMAC)"
	// @Param uid query int true "User ID"
	// @Param exp query int true "Token expiry timestamp"
	// @Param exclude query int false "Message ID to exclude (shown statically)"
	// @Param since query int false "Message ID - newer messages marked as NEW"
	// @Success 200 {object} amp.ChatResponse
	ampGroup.Get("/chat/:id", amp.GetChatMessages)

	// Post reply from AMP email
	// @Router /amp/chat/{id}/reply [post]
	// @Summary Post reply from AMP email
	// @Description Submits an inline reply from AMP email (one-time token)
	// @Tags AMP
	// @Accept json
	// @Produce json
	// @Param id path int true "Chat ID"
	// @Param wt query string true "Write token (one-time nonce)"
	// @Param body body object true "Message body with 'message' field"
	// @Success 200 {object} amp.ReplyResponse
	ampGroup.Post("/chat/:id/reply", amp.PostChatReply)

	// Post reply to a digest-email post (immediate-mode UnifiedDigest)
	// @Router /amp/digest/{id}/reply [post]
	// @Summary Post reply to digest email post
	// @Description Submits an inline reply to a post from an immediate-digest email; opens/finds a chat with the poster
	// @Tags AMP
	// @Accept json
	// @Produce json
	// @Param id path int true "Message ID (the post being replied to)"
	// @Param rt query string true "Token (HMAC)"
	// @Param uid query int true "User ID"
	// @Param exp query int true "Token expiry timestamp"
	// @Param tid query int false "Email tracking ID for analytics"
	// @Param body body object true "Message body with 'message' field"
	// @Success 200 {object} amp.ReplyResponse
	ampGroup.Post("/digest/:id/reply", amp.PostDigestReply)

	// Shared digest reply — identity (mid/rt/exp/uid) and message come from the
	// FORM BODY, so one <amp-form> in the digest template replies to any post.
	// Fewer path segments than /digest/:id/reply, so it's a distinct route.
	// @Router /amp/digest/reply [post]
	// @Summary Post reply to digest email post (shared form)
	// @Description Submits an inline reply to a digest-email post; identity in the body
	// @Tags AMP
	// @Accept x-www-form-urlencoded
	// @Produce json
	// @Param mid formData int true "Message ID (the post being replied to)"
	// @Param rt formData string true "Token (HMAC)"
	// @Param uid formData int true "User ID"
	// @Param exp formData int true "Token expiry timestamp"
	// @Param tid formData int false "Email tracking ID for analytics"
	// @Param message formData string true "Reply text"
	// @Success 200 {object} amp.ReplyResponse
	ampGroup.Post("/digest/reply", amp.PostDigestReplyShared)
}
