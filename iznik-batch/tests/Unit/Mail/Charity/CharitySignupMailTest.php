<?php

namespace Tests\Unit\Mail\Charity;

use Tests\TestCase;

class CharitySignupMailTest extends TestCase
{
    /**
     * Build minimal data to render the charity-signup MJML view directly.
     *
     * @param string $orgName The organisation name to embed in the preheader.
     */
    private function viewData(string $orgName = 'Helping Hands Bristol'): array
    {
        return [
            'charityId'    => 1,
            'orgName'      => $orgName,
            'orgType'      => 'registered',
            'charityNumber' => '1234567',
            'contactEmail' => 'contact@helpinghands.example.com',
            'contactName'  => 'Jane Doe',
            'website'      => 'https://helpinghands.example.com',
            'description'  => 'We help people in need.',
            'siteName'     => 'Freegle',
        ];
    }

    public function test_preheader_contains_new_charity_partner_signup_and_org_name(): void
    {
        $orgName = 'Helping Hands Bristol';

        $html = view('emails.mjml.charity.charity-signup', $this->viewData($orgName))->render();

        $this->assertStringContainsString(
            '<mj-preview>New Charity Partner signup: ' . $orgName . '</mj-preview>',
            $html
        );
    }

    public function test_preheader_reflects_dynamic_org_name(): void
    {
        $orgName = 'Acme Community Trust';

        $html = view('emails.mjml.charity.charity-signup', $this->viewData($orgName))->render();

        $this->assertStringContainsString('Acme Community Trust', $html);
        $this->assertStringContainsString('<mj-preview>New Charity Partner signup: Acme Community Trust</mj-preview>', $html);
    }
}
