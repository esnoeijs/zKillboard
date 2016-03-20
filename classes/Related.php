<?php

class Related
{
    private static $killstorage = array();

    public static function buildSummary(&$kills, $options)
    {
        $involvedEntities = array();
        foreach ($kills as $killID => $kill) {
            self::addAllInvolved($involvedEntities, $killID);
        }

        $teams = self::createTeams($kills);

        foreach ($options as $teamNr => $teamChanges) {
            $teamNr--; // change to 0 based.
            foreach ($teamChanges as $transferEntity) {
                foreach ($teams as $key => &$team) {
                    if (in_array($transferEntity, $team)) {
                        unset($team[$transferEntity]);
                        if (!isset($teams[$teamNr])) {
                            $teams[$teamNr] = array();
                        }
                        $teams[$teamNr][$transferEntity] = (int)$transferEntity;
                        break;
                    }
                }
                unset($team);
            }
        }

        $retValue = [];
        foreach ($teams as $team) {
            $teamInvolved = self::getInvolved($kills, $team);
            $teamLosses   = self::getLosses($kills, $team);

            self::addMoreInvolved($teamInvolved, $teamLosses);
            Info::addInfo($teamInvolved);

            $teamTotals = self::getStatsKillList(array_keys($kills), $team);
            $teamTotals['pilotCount'] = sizeof($teamInvolved);
            $teamMembers = self::addInfo($team);
            asort($teamMembers);

            usort($teamInvolved, 'Related::compareShips');

            $name = self::generateTeamName($teamMembers);

            $retValue[$name] = array(
                'list' => $teamInvolved,
                'kills' => $teamLosses,
                'totals' => $teamTotals,
                'entities' => $teamMembers
            );
        }

        return $retValue;
    }

    private static function addAllInvolved(&$entities, $killID)
    {
        global $mdb;
        $kill = $mdb->findDoc('killmails', ['cacheTime' => 3600, 'killID' => $killID]);

        self::$killstorage[$killID] = $kill;

        $victim = $kill['involved'][0];
        self::addInvolved($entities, $victim);
        $involved = $kill['involved'];
        array_shift($involved);
        if (is_array($involved)) {
            foreach ($involved as $entry) {
                self::addInvolved($entities, $entry);
            }
        }
    }

    private static function addInvolved(&$entities, &$entry)
    {
        $entity = isset($entry['allianceID']) && $entry['allianceID'] != 0 ? $entry['allianceID'] : @$entry['corporationID'];
        if ($entity == 0) {
            return;
        }
        if (!isset($entities["$entity"])) {
            $entities["$entity"] = array();
        }
        if (!in_array(@$entry['characterID'], $entities["$entity"])) {
            $entities["$entity"][] = @$entry['characterID'];
        }
    }

    private static function getInvolved(&$kills, $team)
    {
        $involved = array();
        foreach ($kills as $kill) {
            $kill = self::$killstorage[$kill['victim']['killID']];

            $attackers = $kill['involved'];
            array_shift($attackers);
            if (is_array($attackers)) {
                foreach ($attackers as $entry) {
                    $add = false;
                    if (in_array(@$entry['allianceID'], $team)) {
                        $add = true;
                    }
                    if (in_array(@$entry['corporationID'], $team)) {
                        $add = true;
                    }

                    if ($add) {
                        $key = @$entry['characterID'].':'.@$entry['corporationID'].':'.@$entry['allianceID'].':'.@$entry['shipTypeID'];
                        $entry['shipName'] = Info::getInfoField('typeID', @$entry['shipTypeID'], 'name');
                        if (!in_array($key, $involved)) {
                            $involved[$key] = $entry;
                        }
                    }
                }
            }
        }

        return $involved;
    }

    private static function addMoreInvolved(&$team, $kills)
    {
        foreach ($kills as $kill) {
            $victim = $kill['victim'];
            Info::addInfo($victim);
            if (@$victim['characterID'] > 0 && @$victim['groupID'] != 29) {
                $key = @$victim['characterID'].':'.@$victim['corporationID'].':'.@$victim['allianceID'].':'.$victim['shipTypeID'];
                $victim['destroyed'] = $victim['killID'];
                $team[$key] = $victim;
            }
        }
    }

    private static function getLosses(&$kills, $team)
    {
        $teamsKills = array();
        foreach ($kills as $killID => $kill) {
            $victim = $kill['victim'];
            $add = in_array((int) @$victim['allianceID'], $team) || in_array($victim['corporationID'], $team);

            if ($add) {
                $teamsKills[$killID] = $kill;
            }
        }

        return $teamsKills;
    }

    static $killCache = array();

    private static function getStatsKillList($killIDs, $team)
    {
        $lostIsk = 0;
        $lostPoints = 0;
        $groupIDs = array();
        $totalShips = 0;
        $totalKills = 0;
        $killedPoints = 0;
        $killedIsk  =0;
        foreach ($killIDs as $killID) {
            $isLoss = $isKill = false;
            if (!isset(self::$killCache[$killID])) {
                self::$killCache[$killID] = Kills::getKillDetails($killID);
            }
            $kill = self::$killCache[$killID];
            if (isset($team[self::determineAffiliationId($kill['victim'])])) {
                $isLoss = true;
            } else {
                foreach ($kill['involved'] as $involved) {
                  if (isset($team[self::determineAffiliationId($involved)])) {
                        $isKill = true;
                        break;
                    }
                }
            }

            if ($isKill) {
                $totalKills++;
                $info = $kill['info'];

                $killedPoints += $info['zkb']['points'];
                $killedIsk += $info['zkb']['totalValue'];
            }

            if ($isLoss) {
                $info = $kill['info'];
                $victim = $kill['victim'];
                $lostIsk += $info['zkb']['totalValue'];
                $lostPoints += $info['zkb']['points'];
                $groupID = $victim['groupID'];
                if (!isset($groupIDs[$groupID])) {
                    $groupIDs[$groupID] = array();
                    $groupIDs[$groupID]['count'] = 0;
                    $groupIDs[$groupID]['isk'] = 0;
                    $groupIDs[$groupID]['points'] = 0;
                }
                $groupIDs[$groupID]['groupID'] = $groupID;
                ++$groupIDs[$groupID]['count'];
                $groupIDs[$groupID]['isk'] += $info['zkb']['totalValue'];
                $groupIDs[$groupID]['points'] += $info['zkb']['points'];
                ++$totalShips;
            }
        }
        Info::addInfo($groupIDs);

        $eff = function ($killed, $lost) {
            if ($killed == 0) return 0;
            if ($lost == 0) return -1;

            return ($killed / $lost) * 100;
        };

        return array(
            'total_price' => $lostIsk,
            'groupIDs' => $groupIDs,
            'totalShips' => $totalShips,
            'total_points' => $lostPoints,
            'killed_points' => $killedPoints,
            'killed_isk' => $killedIsk,
            'total_kills' => $totalKills,
            'point_efficiency' => $eff($killedPoints, $lostPoints),
            'isk_efficiency' => $eff($killedIsk, $lostIsk)
        );
    }

    private static function addInfo(&$team)
    {
        global $mdb, $redis;

        $retValue = array();
        foreach ($team as $entity) {
	    $name = $redis->hGet("tq:allianceID:$entity", "name");
	    if ($name == null) $name = $redis->hGet("tq:corporationID:$entity", "name");
	    if ($name == null) $name = $mdb->findField('information', 'name', ['cacheTime' => 3600, 'id' => ((int) $entity)]);
	    if ($name == null) $name = "Entity $entity";
	    $retValue[$entity] = $name;
        }

        return $retValue;
    }

    /**
     * Take all involved parties from the kills and divide them into 2 groups based on who shot who
     *
     * @param array $kills
     * @return array
     */
    private static function createTeams($kills)
    {
        $score = [];
        $entities = [];
        foreach ($kills as $kill) {
            $victim   = $kill['victim'];
            $entities[self::determineEntityId($victim)] = $victim;

            foreach ($kill['involved'] as $involved) {
                $entities[self::determineEntityId($involved)] = $involved;
                self::addScore($victim, $involved, $score);
            }
        }
        $entities = self::sortEntitiesByLargestGroup($entities);

        // Calculate who hates who
        $teams = [
            'red' => [],
            'blue' => []
        ];
        foreach ($entities as $entityId => $entity) {
            $affiliationId = self::determineAffiliationId($entity);
            if (is_null($affiliationId)) {
                continue;
            }

            if (self::calcScore($entity, $score, $teams['red']) <= self::calcScore($entity, $score, $teams['blue'])) {
                $teams['red'][$affiliationId] = $entity;
            } else {
                $teams['blue'][$affiliationId] = $entity;
            }
        }

        // Distill sorted involved parties into their most broad affiliation.
        $groups = [];
        foreach ($teams as $teamName => $team) {
            $groups[$teamName] = [];
            foreach ($team as $entity) {
                $groups[$teamName][self::determineAffiliationId($entity)] = self::determineAffiliationId($entity);
            }
        }

        return array_values($groups);
    }

    /**
     * Returns the Id of the most broad affiliation of the given entity.
     * This can be a corporationID or allianceID
     *
     * @param array $entity
     * @return null|int
     */
    private static function determineAffiliationId($entity)
    {
        foreach (array('allianceID','corporationID') as $possibleId) {
            if (isset($entity[$possibleId])) {
                return $entity[$possibleId];
            }
        }
        return null;
    }

    /**
     * Return the most specific identifier for an entity.
     * This can be characterID, corporationID or allianceID
     *
     * @param array $entity
     * @return null
     */
    private static function determineEntityId($entity)
    {
        foreach (array('characterID','corporationID','allianceID') as $possibleId) {
            if (isset($entity[$possibleId])) {
                return $entity[$possibleId];
            }
        }
        return null;
    }

    /**
     * Add a hate score between two entities for each stage of uniqueness and working both ways.
     *
     * The score list runs both ways and cross affiliation, so corp X can hate char Y for shooting
     * the affiliated pilots, and char Z can hate all pilots from alliance X for shooting him.
     *
     * $score = array(
     *      'victim.characterID' => array(
     *          'involved.characterID' => 5,
     *          'involved.corporationID' => 5,
     *          'involved.allianceID' => 5
     *      ),
     *      'victim.corporationID' => array(
     *          ...
     *      ),
     *      'victim.allianceID' => array(
     *          ...
     *      ),
     *      'involved.characterID' => array(
     *      ...
     *      ...
     * )
     *
     * @param array $victim
     * @param array $involved
     * @param array $score
     */
    private static function addScore($victim, $involved, &$score)
    {
        foreach (array('characterID', 'corporationID', 'allianceID') as $type) {
            foreach (array('characterID', 'corporationID', 'allianceID') as $type2) {
                if (isset($victim[$type]) && isset($involved[$type2])) {
                    $victimId = $victim[$type];
                    $involvedId = $involved[$type2];

                    if (!isset($score[$victimId])) {
                        $score[$victimId] = [];
                    }
                    if (!isset($score[$involvedId])) {
                        $score[$involvedId] = [];
                    }
                    if (!isset($score[$victimId][$involvedId])) {
                        $score[$victimId][$involvedId] = 0;
                    }
                    if (!isset($score[$involvedId][$victimId])) {
                        $score[$involvedId][$victimId] = 0;
                    }

                    $score[$victimId][$involvedId] += 5;
                    $score[$involvedId][$victimId] =& $score[$victimId][$involvedId];
                }
            }
        }
    }

    /**
     * Aggregate the combined hate score based on the members in a given team vs the given entity.
     * Scoring between entities belonging to the same group are ignored. Bads shoot each other too much.
     *
     * @param array $entity
     * @param array $scoreList
     * @param array $team
     * @return int
     */
    private static function calcScore($entity, $scoreList, $team)
    {
        $score = 0;
        foreach ($team as $memberEntity) {
            foreach (array('characterID', 'corporationID', 'allianceID') as $typeId) {
                foreach (array('characterID', 'corporationID', 'allianceID') as $typeId2) {

                    if (isset($entity[$typeId]) && isset($memberEntity[$typeId2])) {
                        if ($entity[$typeId] == $memberEntity[$typeId2]) return -50;

                        // If we have beef, apply the score
                        if (isset($scoreList[$entity[$typeId]][$memberEntity[$typeId2]])) {
                            $score += $scoreList[$entity[$typeId]][$memberEntity[$typeId2]];
                        }
                    }
                }
            }
        }
        return $score;
    }

    public static function compareShips($a, $b)
    {
	global $redis;

        $aSize = (int) $redis->hGet("tq:typeID:" . @$a['shipTypeID'], "mass");
        $bSize = (int) $redis->hGet("tq:typeID:" . @$b['shipTypeID'], "mass");

        return $aSize < $bSize;
    }

    /**
     * find the largest "Blocks" and sort their members to the top
     * There really must be a more elegant way to do this shit.
     *
     * @param array $entities
     * @return array
     */
    private static function sortEntitiesByLargestGroup($entities)
    {
        $sortArray = [];
        $sortOrder = [];
        foreach ($entities as $key => $entity) {
            $groupId = self::determineAffiliationId($entity);
            if (!isset($sortArray[self::determineAffiliationId($entity)])) {
                $sortArray[$groupId] = 1;

            } else {
                $sortArray[$groupId] += 1;
            }
            $sortOrder[$key] = &$sortArray[$groupId];
        }
        array_multisort($sortOrder, SORT_DESC, $entities);

        return $entities;
    }

    /**
     * Generates a team name from parts of the member entity names.
     * The name it generates will be the same for the same group of entities each time.
     *
     * @param $teamMembers
     * @return string
     */
    private static function generateTeamName($teamMembers)
    {
        $name = '';
        foreach ($teamMembers as $member) {
            $parts = array_values(array_filter(preg_split('/( |\.|-)/', $member), function ($part) {
                return (strlen($part) > 3);
            }));
            if (count($parts)> 0) {
                // get a part of the name based on fixed properties
                // that way we get a "random" part of the name, but the same one each time.
                $idx = (strlen($parts[0]) % count($parts));
                $name .= " " . $parts[$idx];

                if (strlen($name) >= 32) {
                    break;
                }
            }
        }

        return $name;
    }
}
