<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesDownloadActivity\Activity;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\ISession;

/**
 * @template-implements IEventListener<Event>
 */
class DownloadEventListener implements IEventListener {
	private ICache $cache;

	public function __construct(
		private Listener $listener,
		private IRequest $request,
		private ISession $session,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('files_downloadactivity_events');
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeZipCreatedEvent) {
			$this->handleBeforeZipCreatedEvent($event);
		} elseif ($event instanceof BeforeNodeReadEvent) {
			$this->handleBeforeNodeReadEvent($event);
		}
	}

	private function handleBeforeZipCreatedEvent(BeforeZipCreatedEvent $event): void {
		if (count($event->getFiles()) !== 0) {
			// Activity will be triggered for each file in the zip by BeforeNodeReadEvent.
			return;
		}

		$node = $event->getFolder();
		if (!($node instanceof Folder)) {
			return;
		}

		$this->cache->set($this->request->getId(), $node->getPath(), 3600);
		$this->listener->readNode($node);
	}

	private function handleBeforeNodeReadEvent(BeforeNodeReadEvent $event): void {
		$node = $event->getNode();
		if (!($node instanceof File)) {
			return;
		}

		try {
			$node->getStorage();
		} catch (NotFoundException) {
			return;
		}

		$folderPath = $this->cache->get($this->request->getId());
		if (is_string($folderPath) && str_starts_with($node->getPath(), $folderPath)) {
			// An activity was published for a containing folder already.
			return;
		}

		// Avoid publishing several activities for one video playing.
		$cacheKey = $node->getId() . $node->getPath() . $this->session->getId();
		if (($this->request->getHeader('range') !== '') && ($this->cache->get($cacheKey) === 'true')) {
			return;
		}
		$this->cache->set($cacheKey, 'true', 3600);

		$this->listener->readNode($node);
	}
}
