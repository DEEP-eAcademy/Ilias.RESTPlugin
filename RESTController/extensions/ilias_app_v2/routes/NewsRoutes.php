<?php

use RESTController\extensions\ILIASApp\V2\data\ErrorAnswer;
use RESTController\extensions\ILIASApp\V2\ILIASAppModel;
use RESTController\extensions\ILIASApp\V2\NewsAPI;
use RESTController\libs\RESTAuth;
use RESTController\RESTController;

/** @var $app RESTController */
/**
 * File NewsRoutes.php
 *
 * All app routes which interact with the ILIAS news.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
$app->group('/news', function() use ($app) {

	$init = function(RESTController $app) {
		require_once __DIR__ . '/../models/data/ErrorAnswer.php';
		require_once __DIR__ . '/../models/NewsAPI.php';

		//set json content type for all sub routes
		$app->response()->headers()->set('Content-Type', 'application/json');

		$app->error(function($message) use ($app) {
			print json_encode(new ErrorAnswer($message));
		});
	};

	$app->get('', RESTAuth::checkAccess(RESTAuth::TOKEN), function() use ($app, $init) {
		try {
			$init($app);
      $iliasApp = new ILIASAppModel();
			 global $DIC;

			$newsApi = new NewsAPI($DIC->language(), $DIC['objDefinition']);
			$responseContent = json_encode($newsApi->findAllNewsForAuthenticatedUser());
			$app->response()->body($responseContent);
		}
		catch (\Exception $exception) {
			$app->error($exception->getMessage());
		}

	});
});