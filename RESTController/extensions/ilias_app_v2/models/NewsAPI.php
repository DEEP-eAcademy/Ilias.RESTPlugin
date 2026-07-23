<?php

namespace RESTController\extensions\ILIASApp\V2;

require_once __DIR__ . '/../../../libs/RESTilias.php';
require_once __DIR__ . '/data/NewsItem.php';

use ILIAS\News\Data\NewsCriteria;
use ILIAS\News\Data\NewsItem as IliasNewsItem;
use ILIAS\News\InternalDataService as NewsInternalDataService;
use ILIAS\News\InternalDomainService as NewsInternalDomainService;
use ILIAS\News\InternalRepoService as NewsInternalRepoService;
use ilLanguage;
use ilNewsItem;
use ilObject;
use ilObjectDefinition;
use ilObjectPlugin;
use RESTController\extensions\ILIASApp\V2\data\block\NewsItem;
use RESTController\libs\RESTilias;

RESTilias::includeIlias('Services/News/classes/class.ilNewsItem.php');

/**
 * Class NewsAPI
 *
 * The news api class wraps the ilNewsItem api and parses
 * the old data in a more suitable format for the Pegasus app.
 *
 * @package RESTController\extensions\ILIASApp\V2
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class NewsAPI {

	/**
	 * @var ilLanguage $language
	 */
	private $language;
	/**
	 * @var ilObjectDefinition $definition
	 */
	private $definition;


	/**
	 * NewsAPI constructor.
	 *
	 * @param ilLanguage         $language
	 * @param ilObjectDefinition $definition
	 */
	public function __construct(ilLanguage $language, ilObjectDefinition $definition) {

		//the news lang module is not loaded by the REST plugin
		$newsLangModuleName = 'news';
		if(!in_array($newsLangModuleName, $language->getUsedModules()))
			$language->loadLanguageModule('news');

		$this->language = $language;
		$this->definition = $definition;
	}


	/**
	 * Find all news entries for the currently authenticated user.
	 * The found news are parsed to NewsItem instances.
	 *
	 * @return NewsItem[] All news found news of the current user.
	 *
	 * @see NewsItem
	 */
	public function findAllNewsForAuthenticatedUser() {
		global $DIC;

		// ilNewsItem::_getNewsItemsOfUser() was removed in ILIAS 10; the closest
		// replacement is $DIC->news()->internal()->domain()->collection(), as used
		// by ilPDNewsBlockGUI/ilUserFeedWriter. But ILIAS\News\Service::internal()
		// unconditionally builds BOTH the domain AND the gui half (InternalService's
		// constructor), and constructing the gui half runs FormAdapterGUI::initJavascript(),
		// which needs the full UI framework bootstrap (initUIFramework()) -- something
		// this API's lightweight bootstrap never runs, causing a
		// "help.text_retriever is not defined" DIC lookup failure. We only need the
		// domain half, so build InternalDomainService directly (same constructor
		// ILIAS\News\Service::internal() itself uses) instead of going through the
		// GUI-coupled facade.
		$dataService = new NewsInternalDataService();
		$repoService = new NewsInternalRepoService($dataService, $DIC->database());
		$domainService = new NewsInternalDomainService($DIC, $repoService, $dataService);

		$user = RESTilias::loadIlUser();
		$onlyPublic = false;

		$period = ilNewsItem::_lookupUserPDPeriod(intval($user->getId()));
		$criteria = new NewsCriteria(period: $period, only_public: $onlyPublic);
		$rawNewsEntries = $domainService->collection()->getNewsForUser($user, $criteria);
		$newsEntries = [];

		/** @var IliasNewsItem $rawNewsEntry */
		foreach ($rawNewsEntries as $rawNewsEntry) {

			// determine the actual content and title
			$obj_id = $rawNewsEntry->getContextObjId();
			$rawObjectType = $rawNewsEntry->getContextObjType();

			$obj_type = $this->translateObjectType($rawObjectType);
			$obj_title = strval(ilObject::_lookupTitle($obj_id));
			$title = sprintf('%s: %s', $obj_type, $obj_title);
			$content = ilNewsItem::determineNewsContent($rawObjectType, $rawNewsEntry->getContent(), $rawNewsEntry->isContentTextIsLangVar());
			$subtitle = $this->translateSubtitle($rawObjectType, $rawNewsEntry->getTitle(), $rawNewsEntry->isContentIsLangVar());

			$newsEntry = new NewsItem();
			$newsEntry
				->setNewsId($rawNewsEntry->getId())
				->setNewsContext($rawNewsEntry->getContextRefId())
				->setTitle($title)
				->setSubtitle((is_null($subtitle)) ? '' : strval($subtitle))
				->setContent((is_null($content)) ? '' : strval($content))
				->setCreateDate($rawNewsEntry->getCreationDate()->getTimestamp())
				->setUpdateDate($rawNewsEntry->getUpdateDate()->getTimestamp());
			$newsEntries[] = $newsEntry;
		}


		// Sorts the values ascending by news id.
		usort($newsEntries, function($a, $b) {
			/**
			 * @var NewsItem $a
			 * @var NewsItem $b
			 */
			return $a->getNewsId() - $b->getNewsId();
		});
		
		return $newsEntries;
	}


	/**
	 * Translates an object type identifier, to human readable text.
	 * For example: xsrl -> learnplace
	 *
	 * @param string $objectType    The object type identifier which should be translated.
	 * @return string               The translated type. xsrl -> learnplace
	 */
	private function translateObjectType($objectType) {
		$obj_type = null;

		if (!$this->definition->isPlugin($objectType))
		{
			$obj_type = $this->language->txt("obj_$objectType");
		}
		else
		{
			RESTilias::includeIlias('Services/Component/classes/class.ilPlugin.php');
			$obj_type = ilObjectPlugin::lookupTxtById($objectType, "obj_$objectType");
		}

		return strval($obj_type);
	}


	/**
	 * Translates the news title.
	 *
	 * @param string $contextObjType   The news entry's context object type.
	 * @param string $title            The news entry's raw title.
	 * @param bool   $contentIsLangVar Whether the title is a language variable.
	 *
	 * @return string The translated news title.
	 */
	private function translateSubtitle($contextObjType, $title, $contentIsLangVar) {
		$subtitle = ilNewsItem::determineNewsTitle($contextObjType, $title, $contentIsLangVar);

		$matches = [];
		if(preg_match('/^-(.*?)-$/', $subtitle,$matches) === 1 && $this->definition->isPlugin($contextObjType)) {
			//fallback if the news service failed to translate a plugin title (BUG ILIAS 5.2.12)
			return ilObjectPlugin::lookupTxtById($contextObjType, $matches[1]);
		}

		return$subtitle;
	}
}