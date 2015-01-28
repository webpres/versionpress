<?php
namespace VersionPress\ChangeInfos;

use Nette\Utils\Strings;
use VersionPress\Git\CommitMessage;
use VersionPress\Utils\ArrayUtils;
use VersionPress\VersionPress;

/**
 * Class representing more changes in one commit
 */
class ChangeInfoEnvelope implements ChangeInfo {

    /**
     * VP meta tag that says the version of VersionPress in which was the commit made.
     * It's parsed into {@link version} field by the {@link buildFromCommitMessage} method.
     */
    const VP_VERSION_TAG = "X-VP-Version";

    /** @var TrackedChangeInfo[] */
    private $changeInfoList;

    /**
     * List of change info classes ordered by their priorities.
     * They are listed in commits / commit table in this order.
     *
     * @var string[]
     */
    private $priorityOrder = array(
        "VersionPress\ChangeInfos\WordPressUpdateChangeInfo",
        "VersionPress\ChangeInfos\VersionPressChangeInfo",
        "VersionPress\ChangeInfos\PostChangeInfo",
        "VersionPress\ChangeInfos\CommentChangeInfo",
        "VersionPress\ChangeInfos\UserChangeInfo",
        "VersionPress\ChangeInfos\RevertChangeInfo",
        "VersionPress\ChangeInfos\PluginChangeInfo",
        "VersionPress\ChangeInfos\ThemeChangeInfo",
        "VersionPress\ChangeInfos\TermChangeInfo",
        "VersionPress\ChangeInfos\OptionChangeInfo",
        "VersionPress\ChangeInfos\PostMetaChangeInfo",
        "VersionPress\ChangeInfos\UserMetaChangeInfo",
    );

    private $version;

    /**
     * @param TrackedChangeInfo[] $changeInfoList
     * @param string|null $version
     */
    public function __construct($changeInfoList, $version = null) {
        $this->changeInfoList = $changeInfoList;
        $this->version = $version === null ? VersionPress::getVersion() : $version;
    }

    /**
     * Creates a commit message from this ChangeInfo. Used by Committer.
     *
     * @see Committer::commit()
     * @return CommitMessage
     */
    public function getCommitMessage() {
        $subject = $this->getChangeDescription();

        $bodies = array();
        foreach ($this->getSortedChangeInfoList() as $changeInfo) {
            $bodies[] = $changeInfo->getCommitMessage()->getBody();
        }

        $body = join("\n\n", $bodies);
        $body .= sprintf("\n\n%s: %s", self::VP_VERSION_TAG, $this->version);

        return new CommitMessage($subject, $body);
    }

    /**
     * Text displayed in the main VersionPress table (see admin/index.php). Also used
     * to construct commit message subject (first line) when the commit is first
     * physically created.
     *
     * @return string
     */
    public function getChangeDescription() {
        $changeList = $this->getSortedChangeInfoList();
        $firstChangeDescription = $changeList[0]->getChangeDescription();
        return $firstChangeDescription;
    }

    /**
     * Factory method - builds a ChangeInfo object from a commit message. Used when VersionPress
     * table is constructed; hooks use the normal constructor.
     *
     * @param CommitMessage $commitMessage
     * @return ChangeInfo
     */
    public static function buildFromCommitMessage(CommitMessage $commitMessage) {
        $fullBody = $commitMessage->getBody();
        $splittedBodies = explode("\n\n", $fullBody);
        $lastBody = $splittedBodies[count($splittedBodies) - 1];
        $changeInfoList = array();
        $version = null;

        if (self::containsVersion($lastBody)) {
            $version = self::extractVersion($lastBody);
            array_pop($splittedBodies);
        }

        foreach ($splittedBodies as $body) {
            $partialCommitMessage = new CommitMessage("", $body);
            /** @var ChangeInfo $matchingChangeInfoType */
            $matchingChangeInfoType = ChangeInfoMatcher::findMatchingChangeInfo($partialCommitMessage);
            $changeInfoList[] = $matchingChangeInfoType::buildFromCommitMessage($partialCommitMessage);
        }

        return new self($changeInfoList, $version);
    }

    /**
     * Returns all ChangeInfo objects encapsulated in ChangeInfoEnvelope.
     *
     * @return TrackedChangeInfo[]
     */
    public function getChangeInfoList() {
        return $this->changeInfoList;
    }

    /**
     * @return TrackedChangeInfo[]
     */
    public function getSortedChangeInfoList() {
        $changeList = $this->changeInfoList;
        ArrayUtils::stablesort($changeList, array($this, 'compareChangeInfoByPriority'));
        return $changeList;
    }

    /**
     * Compare function for usort()
     *
     * @param TrackedChangeInfo $changeInfo1
     * @param TrackedChangeInfo $changeInfo2
     * @return int If $changeInfo1 is more important, returns -1, and the opposite for $changeInfo2. ChangeInfos
     *   of same priorities return zero.
     */
    public function compareChangeInfoByPriority($changeInfo1, $changeInfo2) {
        $class1 = get_class($changeInfo1);
        $class2 = get_class($changeInfo2);

        $priority1 = array_search($class1, $this->priorityOrder);
        $priority2 = array_search($class2, $this->priorityOrder);

        if ($priority1 < $priority2) {
            return -1;
        }

        if ($priority1 > $priority2) {
            return 1;
        }

        // For two VersionPress\ChangeInfos\ThemeChangeInfo objects, the "switch" one wins
        // (Note: the type comparisons can be done for one object only as they are of the same type at this point)
        if ($changeInfo1 instanceof ThemeChangeInfo) {

            if ($changeInfo1->getAction() == "switch") {
                return -1;
            } else if ($changeInfo2->getAction() == "switch") {
                return 1;
            } else {
                return 0;
            }

        }

        // The WPLANG option has always lower priority then any other option (no matter the action)
        if ($changeInfo1 instanceof OptionChangeInfo &&
            $changeInfo2 instanceof OptionChangeInfo) {
            if ($changeInfo1->getEntityId() == "WPLANG") {
                return 1;
            } else if ($changeInfo2->getEntityId() == "WPLANG") {
                return -1;
            }
        }


        if ($changeInfo1 instanceof EntityChangeInfo) {

            // Generally, the "create" action takes precedence
            if ($changeInfo1->getAction() === "create") {
                return -1;
            }

            if ($changeInfo2->getAction() === "create") {
                return 1;
            }

            return 0;

        }

        return 0;
    }

    private static function containsVersion($lastBody) {
        return Strings::startsWith($lastBody, self::VP_VERSION_TAG);
    }

    private static function extractVersion($lastBody) {
        $tmpMessage = new CommitMessage("", $lastBody);
        $version = $tmpMessage->getVersionPressTag(self::VP_VERSION_TAG);
        return $version;
    }
}
