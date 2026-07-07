<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesDownloadActivity\Activity;

use OCA\FilesDownloadActivity\CurrentUser;
use OCP\Activity\IManager;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class Listener {
	public function __construct(
		protected IRequest $request,
		protected IManager $activityManager,
		protected IURLGenerator $urlGenerator,
		protected IRootFolder $rootFolder,
		protected CurrentUser $currentUser,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $path Path of the file that has been read, relative to the user folder
	 */
	public function readFile(string $path): void {
		if ($this->currentUser->getUID() === null) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($this->currentUser->getUID());
			$this->readNode($userFolder->get($path));
		} catch (NotFoundException|InvalidPathException) {
			return;
		}
	}

	public function readNode(Node $node): void {
		if (str_ends_with($node->getName(), '.part')) {
			return;
		}

		if ($this->currentUser->getUID() === null) {
			// User is not logged in, this download is handled by the files_sharing app
			return;
		}

		try {
			[$filePath, $owner, $fileId, $isDir] = $this->resolveNodeForActivity($node);
		} catch (NotFoundException) {
			return;
		}

		if ($this->currentUser->getUID() === $owner) {
			return;
		}

		$client = 'web';
		if ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_DESKTOP])) {
			$client = 'desktop';
		} elseif ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID, IRequest::USER_AGENT_CLIENT_IOS])) {
			$client = 'mobile';
		}
		$subjectParams = [[$fileId => $filePath], $this->currentUser->getUserIdentifier(), $client];

		if ($isDir) {
			$subject = Provider::SUBJECT_SHARED_FOLDER_DOWNLOADED;
			$linkData = [
				'dir' => $filePath,
			];
		} else {
			$subject = Provider::SUBJECT_SHARED_FILE_DOWNLOADED;
			$parentDir = (substr_count($filePath, '/') === 1) ? '/' : dirname($filePath);
			$fileName = basename($filePath);
			$linkData = [
				'dir' => $parentDir,
				'scrollto' => $fileName,
			];
		}

		try {
			$event = $this->activityManager->generateEvent();
			$event->setApp('files_downloadactivity')
				->setType('file_downloaded')
				->setAffectedUser($owner)
				->setAuthor($this->currentUser->getUID())
				->setTimestamp(time())
				->setSubject($subject, $subjectParams)
				->setObject('files', $fileId, $filePath)
				->setLink($this->urlGenerator->linkToRouteAbsolute('files.view.index', $linkData));
			$this->activityManager->publish($event);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		} catch (\BadMethodCallException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * @return array{0: string, 1: string, 2: int, 3: bool}
	 * @throws NotFoundException
	 */
	protected function resolveNodeForActivity(Node $node): array {
		$currentUserId = $this->currentUser->getUID();
		if ($currentUserId === null) {
			throw new NotFoundException('No logged in user');
		}

		$ownerUser = $node->getOwner();
		if ($ownerUser === null) {
			throw new NotFoundException('Node has no owner');
		}
		$owner = $ownerUser->getUID();

		if ($owner !== $currentUserId) {
			try {
				$storage = $node->getStorage();
			} catch (NotFoundException $e) {
				throw $e;
			}

			if ($storage->instanceOfStorage('OCA\Files_Sharing\External\Storage')) {
				// Probably a remote user, let's try to at least generate activities
				// for the current user
				$owner = $currentUserId;
			}

			$ownerFolder = $this->rootFolder->getUserFolder($owner);
			$nodes = $ownerFolder->getById($node->getId());

			if ($nodes === []) {
				throw new NotFoundException($node->getPath());
			}

			$node = $nodes[0];
			$path = substr($node->getPath(), strlen($ownerFolder->getPath()));
		} else {
			$userFolder = $this->rootFolder->getUserFolder($currentUserId);
			$path = substr($node->getPath(), strlen($userFolder->getPath()));
		}

		return [
			$path,
			$owner,
			$node->getId(),
			$node instanceof Folder,
		];
	}
}
