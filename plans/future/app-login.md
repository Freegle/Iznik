# App Login/Signup Improvement Plan

## Current State Summary

### Existing Authentication Methods
1. **Email/Password (Native)** - Traditional signup with email verification
2. **Google Sign-In** - OAuth via Capacitor Social Login plugin
3. **Facebook Login** - OAuth with limited login on iOS for privacy
4. **Apple Sign-In** - iOS only, required by App Store

### Current User Detection
- `loggedInEver` flag persists in localStorage
- `userlist` tracks last 10 user IDs that logged in on device
- `loginType` remembers last authentication method used
- Push notification subscription can identify returning users (server looks up `users_push_notifications.subscription`)

### Current Device Identification
- `Device.getId().identifier` - Capacitor device ID (persists across app restarts)
- `deviceuserinfo` - Readable device string (manufacturer, model, OS)
- Push notification token - Unique per device/app installation

## Requirements Analysis

| Requirement | Priority | Complexity | Notes |
|-------------|----------|------------|-------|
| Google/Apple Sign-In | See below | Already done | App store requirement - with exceptions |
| Cross-platform account sharing | High | Medium | Users on both app and web |
| Frictionless signup | High | High | Reduce barriers to entry |
| Biometric login | Medium | Low | For returning users only |
| Smooth migration for existing users | High | Medium | Don't break existing flows |

### App Store Sign-In Requirements (Updated January 2024)

[Apple revised its guidelines](https://9to5mac.com/2024/01/27/sign-in-with-apple-rules-app-store/) in January 2024:

**Sign in with Apple is NOT required if:**
1. Your app **exclusively uses your company's own account setup** and sign-in systems
2. Education/enterprise apps requiring existing institutional accounts
3. Government ID-backed authentication
4. Client apps for specific third-party services (mail, social media)

**Sign in with Apple IS required if:**
- You offer **any third-party social login** (Google, Facebook, etc.)
- Unless you offer an alternative login service that:
  - Limits data collection to name and email only
  - Allows users to keep email private
  - Does not track users

**Implication for Freegle:**
If we implement device-based auto-accounts with optional email linking (no Google/Facebook login offered), we could potentially **remove the Apple Sign-In requirement**. However:
- We'd lose the convenience of Google Sign-In for users who prefer it
- Web users who signed up with Google couldn't easily link to app
- May cause confusion for existing Google/Apple login users

**Recommended approach:** Keep Google/Apple Sign-In available but make them secondary options behind device-based auto-account. This gives users choice while reducing friction for those who just want to get started.

---

## Research: User Trust in Biometric Authentication

### Key Statistics (2024)

**High Interest and Adoption:**
- [86% of consumers](https://www.iproov.com/blog/biometric-statistics-70) want to use biometrics to verify their identity
- [50% of US users](https://nordvpn.com/blog/us-biometrics-survey/) use at least one biometric (fingerprint, face, eye scan) daily
- 38% already use face recognition for mobile banking; 32% more would if available
- [81% believe](https://www.iproov.com/blog/biometric-statistics-70) biometrics will be used more in future for online identity

**Fingerprint is Most Trusted:**
- [44% rank fingerprint](https://llcbuddy.com/data/biometric-authentication-statistics/) as the most secure authentication method
- Retina scanning: 30%, Alphanumeric passwords: 27%, Facial recognition: only 12%
- [32% of US respondents](https://nordvpn.com/blog/us-biometrics-survey/) use fingerprint scanning daily (most popular biometric)

**Trust Concerns:**
- [41% have little/no trust](https://www.cloudwards.net/biometrics-statistics/) in companies' ability to handle biometric data responsibly
- [15% never use biometrics](https://nordvpn.com/blog/us-biometrics-survey/) because they don't trust the technology
- [58% cite privacy concerns](https://www.cloudwards.net/biometrics-statistics/) as biggest barrier to biometric adoption
- [85% worry about deepfakes](https://www.iproov.com/blog/biometric-statistics-70) making it harder to trust what they see online

**Declining Comfort with Facial Recognition:**
- Retail use: dropped from 81% (2022) to 69% (2024)
- Attendance tracking: dropped from 55% (2022) to 36% (2024)
- Retail purchases: dropped from 49% (2022) to 25% (2024)

### Implications for Freegle

1. **Fingerprint > Face ID in messaging** - Emphasize "Touch ID" over "Face ID" in prompts when possible
2. **On-device only** - Clearly communicate biometric data never leaves device
3. **Optional, not required** - Never force biometric; always offer alternatives
4. **Familiar pattern** - Users already do this for banking apps, so it's understood
5. **Privacy messaging matters** - Include reassurance: "Your fingerprint stays on your device"

### Recommended Prompt Wording

**Instead of:**
> "Enable Face ID for faster login?"

**Use:**
> "Use Touch ID or Face ID to log in instantly?
> Your biometric data stays on your device and is never sent to Freegle."

---

## Proposed Improvements

### Option 1: Device-Based Auto-Account (Recommended)

#### Concept
Create a "guest" account automatically on first app launch using the device's unique identifier. User can browse, post, and message immediately. Account linking happens later when/if needed.

#### Flow
```
First Launch:
  ├─ Get device ID (Capacitor Device.getId())
  ├─ Check if device ID already linked to account
  │   ├─ YES: Auto-login to that account
  │   └─ NO: Create new "device account"
  ├─ Register push notification token
  └─ User starts using app immediately (zero friction)

Later (Optional):
  ├─ User wants to use web or new device
  ├─ Prompt to "secure your account" with email/Google/Apple
  ├─ Link authentication method to existing account
  └─ Account now accessible from any device
```

#### Implementation Details

**New Server Endpoint: Device Login**
```php
// POST /api/session with type=device
$deviceId = Utils::presdef('deviceid', $_REQUEST, NULL);
$deviceInfo = Utils::presdef('deviceinfo', $_REQUEST, NULL);

// Check if device already has account
$existing = $dbhr->preQuery(
    "SELECT userid FROM users_devices WHERE deviceid = ?",
    [$deviceId]
);

if ($existing) {
    // Auto-login to existing account
    $s->create($existing[0]['userid']);
} else {
    // Create new user
    $u = new User($dbhr, $dbhm);
    $userid = $u->create(NULL, NULL, "App User", "Device");

    // Link device
    $dbhm->preExec(
        "INSERT INTO users_devices (userid, deviceid, deviceinfo) VALUES (?, ?, ?)",
        [$userid, $deviceId, $deviceInfo]
    );

    $s->create($userid);
}
```

**New Database Table**
```sql
CREATE TABLE users_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    userid BIGINT UNSIGNED NOT NULL,
    deviceid VARCHAR(255) NOT NULL,
    deviceinfo VARCHAR(255),
    added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (deviceid),
    FOREIGN KEY (userid) REFERENCES users(id) ON DELETE CASCADE
);
```

**Account Linking**
```php
// POST /api/user/link with Google/Apple/Email credentials
// Merges device account with social account if social account exists
// Or links social login to device account if new
```

#### Pros
- Zero friction for new users
- Immediate engagement
- Still supports full account features when needed
- Push notifications work immediately

#### Cons
- Account recovery if device lost/reset (mitigated by prompts to secure account)
- Need to handle account merging carefully
- Device ID may change on OS reinstall

---

### Option 2: Push Token as Identity

#### Concept
Use the FCM push notification token as the primary identifier for app users. This already uniquely identifies a device/app installation.

#### Current State
Server already supports this partially:
```php
// In Session.php
if (!$me && ($pushcreds = Utils::presdef('pushcreds', $_REQUEST, NULL))) {
    $push = $this->dbhr->preQuery(
        "SELECT * FROM users_push_notifications WHERE subscription = ?",
        [$pushcreds]
    );
    if (count($push) > 0) {
        $me = User::get($this->dbhr, $this->dbhm, $push[0]['userid']);
    }
}
```

#### Enhancement
```
First Launch:
  ├─ Request push notification permission
  ├─ Receive FCM token
  ├─ Send token to server
  │   ├─ Token exists: Return associated user
  │   └─ Token new: Create account, link token
  └─ User logged in
```

#### Pros
- Already partially implemented
- Push notifications are essential anyway
- Token is unique per installation

#### Cons
- Requires notification permission upfront (some users decline)
- Token can change (FCM refreshes tokens periodically)
- Doesn't work if user declines push permissions

---

### Option 3: Biometric Login for Returning Users

#### Concept
After initial login (any method), offer to enable biometric login for quick access. Uses device biometrics (Face ID, Touch ID, fingerprint) to unlock stored credentials.

#### Implementation

**Using Capacitor Biometrics Plugin**
```javascript
import { NativeBiometric } from 'capacitor-native-biometric';

// Check availability
const { isAvailable, biometryType } = await NativeBiometric.isAvailable();

// Store credentials after successful login
if (isAvailable) {
    await NativeBiometric.setCredentials({
        username: user.email,
        password: auth.jwt, // Or persistent token
        server: 'ilovefreegle.org'
    });
}

// Retrieve on app launch
const credentials = await NativeBiometric.getCredentials({
    server: 'ilovefreegle.org'
});
// Use credentials.password (JWT) to authenticate
```

#### User Flow
```
First Login (any method):
  ├─ User logs in with Google/Email/Apple
  ├─ Prompt: "Enable Face ID for faster login?"
  │   ├─ YES: Store JWT in secure enclave
  │   └─ NO: Continue normally
  └─ Save preference

Subsequent Opens:
  ├─ Check if biometric enabled
  ├─ Prompt for Face ID/Touch ID
  ├─ On success: Auto-login with stored JWT
  └─ On failure: Show normal login screen
```

#### Pros
- Very fast login for returning users
- Secure (credentials in device secure enclave)
- Familiar UX pattern
- Works alongside any auth method

#### Cons
- Not available on all devices
- Doesn't help with initial signup friction
- JWT expiry needs handling

---

### Option 4: Magic Link Login

#### Concept
Email-based passwordless login. User enters email, receives link, clicks to login.

#### Current State
Already implemented as "Lost Password" feature:
```php
// In User.php
public function forgotPassword($email) {
    // Generates link with user ID and key
    // Sends email with login link
}

public function linkLogin($key) {
    // Validates key and logs user in
}
```

#### Enhancement
- Promote this as primary login method (not just "lost password")
- Add "Sign in with Email" button alongside Google/Apple
- Improve email template for "magic link" messaging
- Consider SMS option for phone numbers

#### Pros
- No password to remember
- Works across devices
- Already implemented

#### Cons
- Requires email access
- Friction of checking email
- Link expiry concerns

---

## Recommended Implementation Plan

### Phase 1: Biometric Login (Quick Win)

**Effort: Low | Impact: Medium**

Add biometric login for returning users. This doesn't change signup but significantly improves returning user experience.

1. Install `capacitor-native-biometric` plugin
2. After successful login, prompt to enable biometrics
3. On app launch, check for stored credentials
4. Authenticate with biometrics before showing main content

### Phase 2: Device-Based Auto-Account

**Effort: Medium | Impact: High**

Implement frictionless signup using device ID.

1. Create `users_devices` table
2. Add device login endpoint
3. On first launch, auto-create account
4. Show "Secure your account" prompts at key moments:
   - After first successful post
   - After first chat message
   - In settings menu
5. Implement account merging for linking

### Phase 3: Improved Returning User Detection

**Effort: Low | Impact: Medium**

Better guide users who have logged in before.

1. If `loggedInEver` is true, show streamlined login
2. Pre-fill email if stored in `userlist`
3. Show "Welcome back" messaging
4. Offer biometric login if enabled
5. Show last used login method prominently

### Phase 4: Smart Login Suggestions

**Effort: Low | Impact: Low**

Use server-side knowledge to help users.

1. When email entered, check if account exists
2. If account has Google login, suggest "Sign in with Google"
3. If account has Apple login, suggest "Sign in with Apple"
4. Show appropriate options based on user history

---

## Migration Strategy for Existing Users

### Detection
```javascript
// On app launch
if (auth.loggedInEver && !auth.user) {
    // User has logged in before but not currently logged in
    showReturningUserFlow();
}
```

### Returning User Flow
1. **Welcome Back Screen**
   - "Welcome back to Freegle!"
   - Show last login method used
   - "We've made logging in easier"

2. **Biometric Setup Prompt**
   - "Enable Face ID for instant access?"
   - One-tap setup after successful login

3. **Account Security Reminder**
   - If using device-only account, prompt to add email/social
   - "Secure your account so you can access it from any device"

### Messaging Examples
```
First time, no previous login:
  "Start freecycling in seconds!"
  [Continue as Guest]  ← Creates device account
  [Sign in with Google/Apple]

Returning user, logged out:
  "Welcome back, [name]!"
  [Use Face ID]  ← If enabled
  [Sign in with Google]  ← If previously used
  [Other sign-in options]

Device account user, key moment:
  "Secure your Freegle account"
  "Add your email so you can access your account from any device"
  [Add Email]  [Remind me later]
```

---

## Technical Considerations

### Device ID Reliability
- Capacitor `Device.getId()` is persistent but can change on:
  - Factory reset
  - App uninstall/reinstall (varies by OS)
- Mitigation: Prompt to secure account before relying solely on device ID

### Push Token Changes
- FCM tokens can refresh
- Server already handles this via PATCH endpoint
- Ensure token updates don't create duplicate accounts

### Account Merging Complexity
- User has device account with posts/messages
- User links Google account that already exists
- Need to merge: messages, posts, group memberships
- Consider: Which name to use? Which settings?

### App Store Requirements
- Apple requires Apple Sign-In if any social login offered
- Google Play requires Google Sign-In availability
- Both already implemented ✓

### GDPR/Privacy
- Device ID is personal data
- Need consent for device-based accounts
- Consider: "By continuing, you agree to our terms"
- Account deletion must clean up device links

---

## Success Metrics

1. **Signup Completion Rate** - % of new users who complete signup
2. **Time to First Post** - How quickly users make their first post
3. **Return Rate** - % of users who return after first session
4. **Login Success Rate** - % of login attempts that succeed
5. **Biometric Adoption** - % of users enabling biometric login
6. **Account Security Rate** - % of device accounts that get secured

---

## Open Questions

1. **How long before prompting device users to secure account?**
   - After first post? After 7 days? After reaching a milestone?

2. **What happens if device ID changes?**
   - User loses access to device account
   - Can recover via linked email/social, or support

3. **Should we migrate existing users to biometric?**
   - Show prompt on next login after feature ships?

4. **How aggressive should "secure account" prompts be?**
   - Balance convenience vs. ensuring users don't lose access

5. **Should device accounts have any limitations?**
   - E.g., can't become group moderator without securing account
