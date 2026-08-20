/*    --- DO NOT EDIT ---
 *
 * This file was generating using api/index.generate.js
 * You can regenerate it by running:
 *
 *     node api/index.generate.js
 *
 *    --- DO NOT EDIT ---
 */

import type AddressAPI from './AddressAPI.js'
import type AdminsAPI from './AdminsAPI.js'
import type AlertAPI from './AlertAPI.js'
import type AuthorityAPI from './AuthorityAPI.js'
import type BanditAPI from './BanditAPI.js'
import type ChatAPI from './ChatAPI.js'
import type CommentAPI from './CommentAPI.js'
import type CommunityEventAPI from './CommunityEventAPI.js'
import type ConfigAPI from './ConfigAPI.js'
import type DashboardAPI from './DashboardAPI.js'
import type DomainAPI from './DomainAPI.js'
import type DonationsAPI from './DonationsAPI.js'
import type EmailTrackingAPI from './EmailTrackingAPI.js'
import type ExportAPI from './ExportAPI.js'
import type GiftAidAPI from './GiftAidAPI.js'
import type GroupAPI from './GroupAPI.js'
import type HousekeeperAPI from './HousekeeperAPI.js'
import type ImageAPI from './ImageAPI.js'
import type IsochroneAPI from './IsochroneAPI.js'
import type JobAPI from './JobAPI.js'
import type LocationAPI from './LocationAPI.js'
import type LogsAPI from './LogsAPI.js'
import type MembershipsAPI from './MembershipsAPI.js'
import type MergeAPI from './MergeAPI.js'
import type MessageAPI from './MessageAPI.js'
import type MicroVolunteeringAPI from './MicroVolunteeringAPI.js'
import type ModConfigsAPI from './ModConfigsAPI.js'
import type NewsAPI from './NewsAPI.js'
import type NoticeboardAPI from './NoticeboardAPI.js'
import type NotificationAPI from './NotificationAPI.js'
import type SessionAPI from './SessionAPI.js'
import type ShortlinksAPI from './ShortlinksAPI.js'
import type SpammersAPI from './SpammersAPI.js'
import type StatusAPI from './StatusAPI.js'
import type StoriesAPI from './StoriesAPI.js'
import type SystemLogsAPI from './SystemLogsAPI.js'
import type TeamAPI from './TeamAPI.js'
import type TrystAPI from './TrystAPI.js'
import type UserAPI from './UserAPI.js'
import type UserSearchAPI from './UserSearchAPI.js'
import type VisualiseAPI from './VisualiseAPI.js'
import type VolunteeringAPI from './VolunteeringAPI.js'

interface API {
  address: AddressAPI
  admins: AdminsAPI
  alert: AlertAPI
  authority: AuthorityAPI
  bandit: BanditAPI
  chat: ChatAPI
  comment: CommentAPI
  communityevent: CommunityEventAPI
  config: ConfigAPI
  dashboard: DashboardAPI
  domain: DomainAPI
  donations: DonationsAPI
  emailtracking: EmailTrackingAPI
  export: ExportAPI
  giftaid: GiftAidAPI
  group: GroupAPI
  housekeeper: HousekeeperAPI
  image: ImageAPI
  isochrone: IsochroneAPI
  job: JobAPI
  location: LocationAPI
  logs: LogsAPI
  memberships: MembershipsAPI
  merge: MergeAPI
  message: MessageAPI
  microvolunteering: MicroVolunteeringAPI
  modconfigs: ModConfigsAPI
  news: NewsAPI
  noticeboard: NoticeboardAPI
  notification: NotificationAPI
  session: SessionAPI
  shortlinks: ShortlinksAPI
  spammers: SpammersAPI
  status: StatusAPI
  stories: StoriesAPI
  systemlogs: SystemLogsAPI
  team: TeamAPI
  tryst: TrystAPI
  user: UserAPI
  usersearch: UserSearchAPI
  visualise: VisualiseAPI
  volunteering: VolunteeringAPI
}

declare module 'vue/types/vue' {
  interface Vue {
    $api: API
  }
}
