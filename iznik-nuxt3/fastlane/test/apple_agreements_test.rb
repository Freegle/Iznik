require 'minitest/autorun'
require_relative '../apple_agreements'

class AppleAgreementsTest < Minitest::Test
  def test_the_developer_portal_refusal_is_an_agreement
    message = 'Unable to process request - PLA Update available - You currently don\'t have ' \
              'access to this membership resource. To resolve this issue, your team\'s Account ' \
              'Holder, A N OTHER, must agree to the latest Program License Agreement.'
    assert AppleAgreements.waiting?(message)
  end

  def test_the_app_store_connect_refusal_is_an_agreement
    assert AppleAgreements.waiting?('A required agreement is missing or has expired.')
    assert AppleAgreements.waiting?('FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED')
  end

  def test_other_errors_are_not
    refute AppleAgreements.waiting?('Build processing timed out')
    refute AppleAgreements.waiting?('Could not find app with identifier org.ilovefreegle.iphone')
    refute AppleAgreements.waiting?(nil)
  end

  def test_the_instruction_says_where_to_go
    assert_includes AppleAgreements.instruction, 'https://developer.apple.com/account'
  end
end
