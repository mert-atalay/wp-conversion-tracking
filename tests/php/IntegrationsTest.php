<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey\Actions;
use LCT\Integrations\ContactForm7;
use LCT\Integrations\WPForms;

final class IntegrationsTest extends TestCase {

	public function test_contact_form_7_hooks_into_mail_sent(): void {
		$integration = new ContactForm7();
		$integration->register();

		$this->assertNotFalse( has_action( 'wpcf7_mail_sent', array( $integration, 'on_mail_sent' ) ) );
	}

	public function test_contact_form_7_reports_the_form_id(): void {
		$form = new class() {
			public function id(): int {
				return 123;
			}
		};

		Actions\expectDone( 'lct_form_submitted' )->once()->with( '123', 'cf7', '' );

		( new ContactForm7() )->on_mail_sent( $form );
	}

	public function test_contact_form_7_ignores_unexpected_arguments(): void {
		Actions\expectDone( 'lct_form_submitted' )->never();

		( new ContactForm7() )->on_mail_sent( null );
		( new ContactForm7() )->on_mail_sent( 'form' );
	}

	public function test_wpforms_hooks_into_process_complete(): void {
		$integration = new WPForms();
		$integration->register();

		$this->assertNotFalse( has_action( 'wpforms_process_complete', array( $integration, 'on_complete' ) ) );
	}

	public function test_wpforms_reports_the_form_id_and_the_first_email_field(): void {
		$fields = array(
			1 => array( 'type' => 'name', 'value' => 'A Person' ),
			2 => array( 'type' => 'email', 'value' => 'person@example.com' ),
			3 => array( 'type' => 'email', 'value' => 'other@example.com' ),
		);

		Actions\expectDone( 'lct_form_submitted' )->once()->with( '9', 'wpforms', 'person@example.com' );

		( new WPForms() )->on_complete( $fields, array(), array( 'id' => '9' ) );
	}

	public function test_wpforms_without_a_form_id_reports_nothing(): void {
		Actions\expectDone( 'lct_form_submitted' )->never();

		( new WPForms() )->on_complete( array(), array(), array() );
		( new WPForms() )->on_complete( array(), array(), null );
	}
}
