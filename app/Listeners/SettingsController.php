<?php
/**
 * Pim
 * Free Extension
 * Copyright (c) TreoLabs GmbH
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Pim\Listeners;

use Espo\Core\Exceptions\BadRequest;
use Pim\Entities\Attribute as AttributeEntity;
use Pim\Entities\Channel;
use Pim\Repositories\Attribute as AttributeRepository;
use Treo\Listeners\AbstractListener;
use Treo\Core\EventManager\Event;

/**
 * Class SettingsController
 *
 * @author r.ratsun <r.ratsun@treolabs.com>
 */
class SettingsController extends AbstractListener
{
    /**
     * @param Event $event
     */
    public function beforeActionUpdate(Event $event): void
    {
        // open session
        session_start();

        // set to session
        $_SESSION['isMultilangActive'] = $this->getConfig()->get('isMultilangActive', false);
        $_SESSION['inputLanguageList'] = $this->getConfig()->get('inputLanguageList', []);
    }

    /**
     * @param Event $event
     */
    public function afterActionUpdate(Event $event): void
    {
        $this->updateChannelsLocales();

        $this->updateAttributes();

        // cleanup
        unset($_SESSION['isMultilangActive']);
        unset($_SESSION['inputLanguageList']);
    }

    /**
     * Update Channel locales field
     */
    protected function updateChannelsLocales(): void
    {
        if (!$this->getConfig()->get('isMultilangActive', false)) {
            $this->getEntityManager()->nativeQuery("UPDATE channel SET locales=NULL WHERE 1");
        } elseif (!empty($_SESSION['isMultilangActive'])) {
            /** @var array $deletedLocales */
            $deletedLocales = array_diff($_SESSION['inputLanguageList'], $this->getConfig()->get('inputLanguageList', []));

            /** @var Channel[] $channels */
            $channels = $this
                ->getEntityManager()
                ->getRepository('Channel')
                ->select(['id', 'locales'])
                ->find();

            if (count($channels) > 0) {
                foreach ($channels as $channel) {
                    if (!empty($locales = $channel->get('locales'))) {
                        $newLocales = [];
                        foreach ($locales as $locale) {
                            if (!in_array($locale, $deletedLocales)) {
                                $newLocales[] = $locale;
                            }
                        }
                        $channel->set('locales', $newLocales);
                        $this->getEntityManager()->saveEntity($channel);
                    }
                }
            }
        }
    }

    /**
     * Update multi-lang attributes
     */
    protected function updateAttributes()
    {
        if (!$this->getConfig()->get('isMultilangActive', false)) {
            // delete all
            $this->getEntityManager()->nativeQuery(
                "UPDATE attribute SET deleted=1 WHERE locale IS NOT NULL;UPDATE product_family_attribute SET deleted=1 WHERE locale IS NOT NULL;UPDATE product_attribute_value SET deleted=1 WHERE locale IS NOT NULL"
            );
        } else {
            /** @var AttributeRepository $repository */
            $repository = $this->getEntityManager()->getRepository('Attribute');

            /** @var AttributeEntity[] $attributes */
            $attributes = $repository
                ->where(['isMultilang' => true])
                ->find();

            if (count($attributes) > 0) {
                /** @var array $allLocales */
                $allLocales = $this->getConfig()->get('inputLanguageList', []);

                /** @var array $addedLocales */
                $addedLocales = !$_SESSION['isMultilangActive'] ? $allLocales : array_diff($allLocales, $_SESSION['inputLanguageList']);

                // create
                if (!empty($addedLocales)) {
                    foreach ($attributes as $attribute) {
                        try {
                            $repository->createLocaleAttribute($attribute, $addedLocales);
                        } catch (BadRequest $e) {
                            $GLOBALS['log']->error('BadRequest: ' . $e->getMessage());
                        }
                    }
                }

                /** @var array $deletedLocales */
                $deletedLocales = !$_SESSION['isMultilangActive'] ? [] : array_diff($_SESSION['inputLanguageList'], $allLocales);

                // delete
                if (!empty($deletedLocales)) {
                    $localesStr = implode("','", $deletedLocales);
                    $this->getEntityManager()->nativeQuery(
                        "UPDATE attribute SET deleted=1 WHERE locale IN ('$localesStr');UPDATE product_family_attribute SET deleted=1 WHERE locale IN ('$localesStr');UPDATE product_attribute_value SET deleted=1 WHERE locale IN ('$localesStr')"
                    );
                }
            }
        }
    }
}
