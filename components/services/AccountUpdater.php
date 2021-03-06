<?php
/**
 * Created for IG Monitoring.
 * User: jakim <pawel@jakimowski.info>
 * Date: 18.06.2018
 */

namespace app\components\services;


use app\components\builders\AccountBuilder;
use app\components\http\Client;
use app\components\http\ProxyManager;
use app\components\instagram\AccountScraper;
use app\components\instagram\models\Account;
use app\components\MediaManager;
use app\components\services\contracts\ServiceInterface;
use app\dictionaries\AccountInvalidationType;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Jakim\Exception\LoginAndSignupPageException;
use Jakim\Exception\RestrictedProfileException;
use Yii;
use yii\base\BaseObject;
use yii\web\NotFoundHttpException;

class AccountUpdater extends BaseObject implements ServiceInterface
{
    /**
     * @var \app\models\Account
     */
    public $account;

    public function run()
    {
        $proxyManager = Yii::createObject(ProxyManager::class);
        $accountBuilder = Yii::createObject([
            'class' => AccountBuilder::class,
            'account' => $this->account,
        ]);

        try {
            $proxy = $proxyManager->reserve();
            $httpClient = Client::factory($proxy);
            $scraper = Yii::createObject(AccountScraper::class, [
                $httpClient,
            ]);

            $accountData = $this->fetchAccountData($scraper);
            $posts = $scraper->fetchLastPosts($accountData->username);

            $proxyManager->release($proxy, false);
            unset($proxy);

            if ($accountData->isPrivate) {
                $accountBuilder
                    ->setIsInValid(AccountInvalidationType::IS_PRIVATE)
                    ->setNextStatsUpdate(true)
                    ->save();
            } else {
                $accountBuilder
                    ->setDetails($accountData)
                    ->setIdents($accountData)
                    ->setIsValid()
                    ->setStats($accountData, $posts)
                    ->setNextStatsUpdate()
                    ->save();

                $mediaManager = Yii::createObject(MediaManager::class);
                $mediaManager->addToAccount($this->account, $posts);

            }

        } catch (NotFoundHttpException $exception) {
            $accountBuilder
                ->setIsInValid(AccountInvalidationType::NOT_FOUND)
                ->setNextStatsUpdate(true)
                ->save();
        } catch (RestrictedProfileException $exception) {
            $accountBuilder
                ->setIsInValid(AccountInvalidationType::RESTRICTED_PROFILE)
                ->setNextStatsUpdate(true)
                ->save();
        } catch (LoginAndSignupPageException $exception) {
            $accountBuilder
                ->setNextStatsUpdate(1)
                ->save();
            if (isset($proxy)) { // must be
                $proxyManager->release($proxy, true);
            }
        } catch (RequestException $exception) {
            $accountBuilder
                ->setIsInValid()
                ->setNextStatsUpdate(true)
                ->save();
        } finally {
            if (isset($proxy)) {
                $proxyManager->release($proxy);
            }
        }

    }

    private function fetchAccountData(AccountScraper $scraper): Account
    {
        $idents = array_filter([
            $this->account->username,
        ]);

        foreach ($idents as $ident) {
            try {
                $accountData = $scraper->fetchOne($ident);
                if ($this->account->instagram_id && $accountData->id != $this->account->instagram_id) {
                    continue;
                }
            } catch (ClientException $exception) {
                Yii::error($exception->getMessage(), __METHOD__);
                continue;
            }
            break;
        }

        if (empty($accountData)) {
            throw new NotFoundHttpException();
        }

        return $accountData;
    }

}