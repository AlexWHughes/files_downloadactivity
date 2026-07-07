<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesDownloadActivity\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings {
	public function __construct(
		protected IL10N $l,
	) {
	}

	public function getIdentifier(): string {
		return 'file_downloaded';
	}

	public function getName(): string {
		return $this->l->t('A local shared file or folder was <strong>downloaded</strong>');
	}

	public function getGroupIdentifier(): string {
		return 'sharing';
	}

	public function getGroupName(): string {
		return $this->l->t('Sharing');
	}

	public function getPriority(): int {
		return 21;
	}

	public function canChangeNotification(): bool {
		return true;
	}

	public function isDefaultEnabledNotification(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
