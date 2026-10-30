# Apple refuses App Store Connect and developer-portal requests while an agreement is waiting
# for the Account Holder, such as an updated Program License Agreement. Only the Account Holder
# can accept it, signed in on Apple's site: there is no API for it. These helpers spot that
# refusal so a lane can say what to do instead of failing with a raw Apple error.
module AppleAgreements
  # Wordings Apple has used. The developer portal says "PLA Update available ... must agree to
  # the latest Program License Agreement" (2026-10-29); App Store Connect says "A required
  # agreement is missing or has expired" (FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED).
  PATTERNS = [
    /Program License Agreement/i,
    /PLA Update available/i,
    /REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED/i,
    /required agreement is missing or has expired/i
  ].freeze

  def self.waiting?(message)
    text = message.to_s
    PATTERNS.any? { |p| p.match?(text) }
  end

  def self.instruction
    'Apple is refusing requests until the Account Holder accepts an updated agreement. ' \
      'Sign in at https://developer.apple.com/account as the Account Holder and accept the ' \
      'agreement shown there, and any waiting in App Store Connect under Business. ' \
      'Then re-run the failed job.'
  end
end
