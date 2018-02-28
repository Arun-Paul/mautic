<?php

/*
 * @copyright   2017 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Executioner\Logger;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\AbstractEventAccessor;
use Mautic\CampaignBundle\Helper\ChannelExtractor;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\LeadBundle\Model\LeadModel;

class EventLogger
{
    /**
     * @var IpLookupHelper
     */
    private $ipLookupHelper;

    /**
     * @var LeadModel
     */
    private $leadModel;

    /**
     * @var LeadEventLogRepository
     */
    private $repo;

    /**
     * @var ArrayCollection
     */
    private $queued;

    /**
     * @var ArrayCollection
     */
    private $processed;

    /**
     * LogHelper constructor.
     *
     * @param IpLookupHelper         $ipLookupHelper
     * @param LeadModel              $leadModel
     * @param LeadEventLogRepository $repo
     */
    public function __construct(IpLookupHelper $ipLookupHelper, LeadModel $leadModel, LeadEventLogRepository $repo)
    {
        $this->ipLookupHelper = $ipLookupHelper;
        $this->leadModel      = $leadModel;
        $this->repo           = $repo;

        $this->queued    = new ArrayCollection();
        $this->processed = new ArrayCollection();
    }

    /**
     * @param LeadEventLog $log
     */
    public function addToQueue(LeadEventLog $log)
    {
        $this->queued->add($log);

        if ($this->queued->count() >= 20) {
            $this->persistQueued();
        }
    }

    /**
     * @param LeadEventLog $log
     */
    public function persistLog(LeadEventLog $log)
    {
        $this->repo->saveEntity($log);
    }

    /**
     * @param Event $event
     * @param null  $lead
     * @param bool  $inactive
     *
     * @return LeadEventLog
     */
    public function buildLogEntry(Event $event, $lead = null, $inactive = false)
    {
        $log = new LeadEventLog();

        $log->setIpAddress($this->ipLookupHelper->getIpAddress());

        $log->setEvent($event);
        $log->setCampaign($event->getCampaign());

        if ($lead == null) {
            $lead = $this->leadModel->getCurrentLead();
        }
        $log->setLead($lead);

        if ($inactive) {
            $log->setNonActionPathTaken(true);
        }

        $log->setDateTriggered(new \DateTime());
        $log->setSystemTriggered(defined('MAUTIC_CAMPAIGN_SYSTEM_TRIGGERED'));

        return $log;
    }

    /**
     * Persist the queue, clear the entities from memory, and reset the queue.
     */
    public function persistQueued()
    {
        if ($this->queued->count()) {
            $this->repo->saveEntities($this->queued->getValues());
        }

        // Push them into the processed ArrayCollection to be used later.
        /** @var LeadEventLog $log */
        foreach ($this->queued as $log) {
            $this->processed->set($log->getId(), $log);
        }

        $this->queued->clear();
    }

    /**
     * @return ArrayCollection
     */
    public function getLogs()
    {
        return $this->processed;
    }

    /**
     * @param ArrayCollection $collection
     *
     * @return $this
     */
    public function persistCollection(ArrayCollection $collection)
    {
        if (!$collection->count()) {
            return $this;
        }

        $this->repo->saveEntities($collection->getValues());

        return $this;
    }

    /**
     * @param ArrayCollection $collection
     *
     * @return $this
     */
    public function clearCollection(ArrayCollection $collection)
    {
        $this->repo->detachEntities($collection->getValues());

        return $this;
    }

    /**
     * Persist processed entities after they've been updated.
     *
     * @return $this
     */
    public function persist()
    {
        if (!$this->processed->count()) {
            return $this;
        }

        $this->repo->saveEntities($this->processed->getValues());

        return $this;
    }

    /**
     * @return $this
     */
    public function clear()
    {
        $this->processed->clear();
        $this->repo->clear();

        return $this;
    }

    /**
     * @param ArrayCollection $logs
     *
     * @return ArrayCollection
     */
    public function extractContactsFromLogs(ArrayCollection $logs)
    {
        $contacts = new ArrayCollection();

        /** @var LeadEventLog $log */
        foreach ($logs as $log) {
            $contact = $log->getLead();
            $contacts->set($contact->getId(), $contact);
        }

        return $contacts;
    }

    /**
     * @param Event                 $event
     * @param AbstractEventAccessor $config
     * @param ArrayCollection       $contacts
     * @param bool                  $inactive
     *
     * @return ArrayCollection
     */
    public function generateLogsFromContacts(Event $event, AbstractEventAccessor $config, ArrayCollection $contacts, $inactive = false)
    {
        // Ensure each contact has a log entry to prevent them from being picked up again prematurely
        foreach ($contacts as $contact) {
            $log = $this->buildLogEntry($event, $contact, $inactive);
            $log->setIsScheduled(false);
            $log->setDateTriggered(new \DateTime());

            ChannelExtractor::setChannel($log, $event, $config);

            $this->addToQueue($log);
        }

        $this->persistQueued();

        return $this->getLogs();
    }
}
