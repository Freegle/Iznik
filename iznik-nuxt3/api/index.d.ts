
/*    --- DO NOT EDIT ---
 *
 * This file was generating using api/index.generate.js
 * You can regenerate it by running:
 *
 *     node api/index.generate.js
 *
 *    --- DO NOT EDIT ---
 */


import AIImagesAPI from './AIImagesAPI.js'
import AddressAPI from './AddressAPI.js'
import AdminsAPI from './AdminsAPI.js'
import AlertAPI from './AlertAPI.js'
import AuthorityAPI from './AuthorityAPI.js'
import BanditAPI from './BanditAPI.js'
import BrowseAPI from './BrowseAPI.js'
import CharityAPI from './CharityAPI.js'
import ChatAPI from './ChatAPI.js'
import CommentAPI from './CommentAPI.js'
import CommunityEventAPI from './CommunityEventAPI.js'
import ConfigAPI from './ConfigAPI.js'
import DashboardAPI from './DashboardAPI.js'
import DomainAPI from './DomainAPI.js'
import DonationsAPI from './DonationsAPI.js'
import DrivingAPI from './DrivingAPI.js'
import ElectricalsAPI from './ElectricalsAPI.js'
import EmailTrackingAPI from './EmailTrackingAPI.js'
import ExportAPI from './ExportAPI.js'
import GiftAidAPI from './GiftAidAPI.js'
import GroupAPI from './GroupAPI.js'
import HousekeeperAPI from './HousekeeperAPI.js'
import ImageAPI from './ImageAPI.js'
import IsochroneAPI from './IsochroneAPI.js'
import JobAPI from './JobAPI.js'
import LocationAPI from './LocationAPI.js'
import LockdownAPI from './LockdownAPI.js'
import LogsAPI from './LogsAPI.js'
import MembershipsAPI from './MembershipsAPI.js'
import MergeAPI from './MergeAPI.js'
import MessageAPI from './MessageAPI.js'
import MicroVolunteeringAPI from './MicroVolunteeringAPI.js'
import ModConfigsAPI from './ModConfigsAPI.js'
import NewsAPI from './NewsAPI.js'
import NoticeboardAPI from './NoticeboardAPI.js'
import NotificationAPI from './NotificationAPI.js'
import PartnershipsAPI from './PartnershipsAPI.js'
import RecommendationsAPI from './RecommendationsAPI.js'
import RipplingAPI from './RipplingAPI.js'
import SessionAPI from './SessionAPI.js'
import ShortlinksAPI from './ShortlinksAPI.js'
import SpammersAPI from './SpammersAPI.js'
import StatusAPI from './StatusAPI.js'
import StoriesAPI from './StoriesAPI.js'
import SystemLogsAPI from './SystemLogsAPI.js'
import TeamAPI from './TeamAPI.js'
import TownAPI from './TownAPI.js'
import TrystAPI from './TrystAPI.js'
import UserAPI from './UserAPI.js'
import UserSearchAPI from './UserSearchAPI.js'
import VisualiseAPI from './VisualiseAPI.js'
import VolunteeringAPI from './VolunteeringAPI.js'

interface API {
  aiimages: AIImagesAPI;
  address: AddressAPI;
  admins: AdminsAPI;
  alert: AlertAPI;
  authority: AuthorityAPI;
  bandit: BanditAPI;
  browse: BrowseAPI;
  charity: CharityAPI;
  chat: ChatAPI;
  comment: CommentAPI;
  communityevent: CommunityEventAPI;
  config: ConfigAPI;
  dashboard: DashboardAPI;
  domain: DomainAPI;
  donations: DonationsAPI;
  driving: DrivingAPI;
  electricals: ElectricalsAPI;
  emailtracking: EmailTrackingAPI;
  export: ExportAPI;
  giftaid: GiftAidAPI;
  group: GroupAPI;
  housekeeper: HousekeeperAPI;
  image: ImageAPI;
  isochrone: IsochroneAPI;
  job: JobAPI;
  location: LocationAPI;
  lockdown: LockdownAPI;
  logs: LogsAPI;
  memberships: MembershipsAPI;
  merge: MergeAPI;
  message: MessageAPI;
  microvolunteering: MicroVolunteeringAPI;
  modconfigs: ModConfigsAPI;
  news: NewsAPI;
  noticeboard: NoticeboardAPI;
  notification: NotificationAPI;
  partnerships: PartnershipsAPI;
  recommendations: RecommendationsAPI;
  rippling: RipplingAPI;
  session: SessionAPI;
  shortlinks: ShortlinksAPI;
  spammers: SpammersAPI;
  status: StatusAPI;
  stories: StoriesAPI;
  systemlogs: SystemLogsAPI;
  team: TeamAPI;
  town: TownAPI;
  tryst: TrystAPI;
  user: UserAPI;
  usersearch: UserSearchAPI;
  visualise: VisualiseAPI;
  volunteering: VolunteeringAPI;
}

declare module 'vue/types/vue' {
  interface Vue {
    $api: API;
  }
}