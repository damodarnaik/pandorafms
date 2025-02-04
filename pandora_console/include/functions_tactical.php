<?php
/**
 * Tactical view functions script
 *
 * @category   Functions
 * @package    Pandora FMS
 * @subpackage Tactical View
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2021 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation for version 2.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * ============================================================================
 */

// Begin.


/**
 * Undocumented function
 *
 * @param  boolean $id_user
 * @param  boolean $user_strict
 * @param  array   $acltags
 * @param  boolean $returnAllGroup
 * @param  string  $mode
 * @param  array   $agent_filter
 * @param  array   $module_filter
 * @return void
 */
function tactical_get_data(
    $id_user=false,
    $user_strict=false,
    $acltags=[],
    $returnAllGroup=false,
    $mode='group',
    $agent_filter=[],
    $module_filter=[]
) {
    global $config;
    if ($id_user == false) {
        $id_user = $config['id_user'];
    }

    $user_groups = [];
    $user_tags = [];
    $groups_without_tags = [];
    foreach ($acltags as $group => $tags) {
        if ($user_strict) {
            // Remove groups with tags
            $groups_without_tags[$group] = $group;
        }

        if ($tags != '') {
            $tags_group = explode(',', $tags);

            foreach ($tags_group as $tag) {
                $user_tags[$tag] = tags_get_name($tag);
            }
        }
    }

    if ($user_strict) {
        $user_groups_ids = implode(',', array_keys($groups_without_tags));
    } else {
        $user_groups_ids = implode(',', array_keys($acltags));
    }

    if (empty($user_groups_ids)) {
        $user_groups_ids = 'null';
    }

    if (!empty($user_groups_ids)) {
        $list_groups = db_get_all_rows_sql(
            '
            SELECT *
            FROM tgrupo
            WHERE id_grupo IN ('.$user_groups_ids.')
            ORDER BY nombre ASC'
        );
    }

    $list = [];
    $list['_monitors_critical_'] = 0;
    $list['_monitors_warning_'] = 0;
    $list['_monitors_unknown_'] = 0;
    $list['_monitors_not_init_'] = 0;
    $list['_monitors_ok_'] = 0;
    $list['_monitors_alerts_fired_'] = 0;

    if (empty($list_groups)) {
        $list_groups = [];
    }

    /*
     * Agent cache for metaconsole.
     * Retrieve the statistic data from the cache table.
     */
    if (is_metaconsole() && !empty($list_groups)) {
        $cache_table = 'tmetaconsole_agent';

        if (users_is_admin() === false) {
            $user_groups_ids_array = explode(',', $user_groups_ids);

            $user_group_children_ids = [];

            foreach ($user_groups_ids_array as $user_group_id) {
                $group_children_ids = groups_get_children_ids($user_group_id);
                $user_group_children_ids = array_merge($user_group_children_ids, $group_children_ids);
            }

            $user_groups_ids = implode(',', array_unique($user_group_children_ids));
        }

        // Subquery is needed for avoid possible duplicity in id_agente.
        $sql_stats = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_total,
            SUM(tma.total_count) AS monitors_total,
            SUM(tma.normal_count) AS monitors_ok,
            SUM(tma.warning_count) AS monitors_warning,
			SUM(tma.critical_count) AS monitors_critical,
			SUM(tma.unknown_count) AS monitors_unknown,
			SUM(tma.notinit_count) AS monitors_not_init,
			SUM(tma.fired_count) AS alerts_fired
			FROM tmetaconsole_agent tma
            WHERE tma.disabled = 0
            AND tma.id_agente IN (
                SELECT DISTINCT tmag.id_agente FROM tmetaconsole_agent tmag
                LEFT JOIN tmetaconsole_agent_secondary_group tmasg
                ON tmag.id_agente = tmasg.id_agent WHERE tmag.id_grupo IN (%s) OR tmasg.id_group IN (%s)
            )
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats = db_get_all_rows_sql($sql_stats);

        $sql_stats_unknown = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_unknown
            FROM tmetaconsole_agent tma
            LEFT JOIN tmetaconsole_agent_secondary_group tmasg
            ON tma.id_agente = tmasg.id_agent
			WHERE tma.disabled = 0
			AND (tma.id_grupo IN (%s) OR tmasg.id_group IN (%s))
			AND tma.critical_count = 0
			AND tma.warning_count = 0
			AND tma.unknown_count > 0
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats_unknown = db_get_all_rows_sql($sql_stats_unknown);

        $sql_stats_not_init = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_not_init
			FROM tmetaconsole_agent tma
            LEFT JOIN tmetaconsole_agent_secondary_group tmasg
            ON tma.id_agente = tmasg.id_agent
			WHERE tma.disabled = 0
			AND (tma.id_grupo IN (%s) OR tmasg.id_group IN (%s))
			AND (tma.total_count = 0 OR tma.total_count = tma.notinit_count)
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats_not_init = db_get_all_rows_sql($sql_stats_not_init);

        $sql_stats_ok = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_ok
            FROM tmetaconsole_agent tma
            LEFT JOIN tmetaconsole_agent_secondary_group tmasg
            ON tma.id_agente = tmasg.id_agent
			WHERE tma.disabled = 0
			AND (tma.id_grupo IN (%s) OR tmasg.id_group IN (%s))
			AND tma.critical_count = 0
			AND tma.warning_count = 0
			AND tma.unknown_count = 0
			AND tma.normal_count > 0
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats_ok = db_get_all_rows_sql($sql_stats_ok);

        $sql_stats_warning = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_warning
			FROM tmetaconsole_agent tma
            LEFT JOIN tmetaconsole_agent_secondary_group tmasg
            ON tma.id_agente = tmasg.id_agent
			WHERE tma.disabled = 0
			AND (tma.id_grupo IN (%s) OR tmasg.id_group IN (%s))
			AND tma.critical_count = 0
			AND tma.warning_count > 0
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats_warning = db_get_all_rows_sql($sql_stats_warning);

        $sql_stats_critical = sprintf(
            'SELECT tma.id_grupo, COUNT(tma.id_agente) AS agents_critical
			FROM tmetaconsole_agent tma
            LEFT JOIN tmetaconsole_agent_secondary_group tmasg
            ON tma.id_agente = tmasg.id_agent
			WHERE tma.disabled = 0
			AND (tma.id_grupo IN (%s) OR tmasg.id_group IN (%s))
			AND tma.critical_count > 0
            GROUP BY tma.id_grupo',
            $user_groups_ids,
            $user_groups_ids
        );

        $data_stats_critical = db_get_all_rows_sql($sql_stats_critical);

        if (!empty($data_stats)) {
            foreach ($data_stats as $value) {
                $list['_total_agents_'] += (int) $value['agents_total'];
                $list['_monitors_ok_'] += (int) $value['monitors_ok'];
                $list['_monitors_critical_'] += (int) $value['monitors_critical'];
                $list['_monitors_warning_'] += (int) $value['monitors_warning'];
                $list['_monitors_unknown_'] += (int) $value['monitors_unknown'];
                $list['_monitors_not_init_'] += (int) $value['monitors_not_init'];
                $list['_monitors_alerts_fired_'] += (int) $value['alerts_fired'];
            }

            if (!empty($data_stats_unknown)) {
                foreach ($data_stats_unknown as $value) {
                    $list['_agents_unknown_'] += (int) $value['agents_unknown'];
                }
            }

            if (!empty($data_stats_not_init)) {
                foreach ($data_stats_not_init as $value) {
                    $list['_agents_not_init_'] += (int) $value['agents_not_init'];
                }
            }

            if (!empty($data_stats_ok)) {
                foreach ($data_stats_ok as $value) {
                    $list['_agents_ok_'] += (int) $value['agents_ok'];
                }
            }

            if (!empty($data_stats_warning)) {
                foreach ($data_stats_warning as $value) {
                    $list['_agents_warning_'] += (int) $value['agents_warning'];
                }
            }

            if (!empty($data_stats_critical)) {
                foreach ($data_stats_critical as $value) {
                    $list['_agents_critical_'] += (int) $value['agents_critical'];
                }
            }
        }
    }

    if (is_metaconsole()) {
        // Agent cache
        // Get total count of monitors for this group, except disabled.
        $list['_monitor_checks_'] = ($list['_monitors_not_init_'] + $list['_monitors_unknown_'] + $list['_monitors_warning_'] + $list['_monitors_critical_'] + $list['_monitors_ok_']);

        // Calculate not_normal monitors
        $list['_monitor_not_normal_'] = ($list[$i]['_monitor_checks_'] - $list['_monitors_ok_']);

        if ($list['_monitor_not_normal_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_monitor_health_'] = format_numeric((100 - ($list['_monitor_not_normal_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_monitor_health_'] = 100;
        }

        if ($list['_monitors_not_init_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_module_sanity_'] = format_numeric((100 - ($list['_monitors_not_init_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_module_sanity_'] = 100;
        }

        if (isset($list[$i]['_alerts_'])) {
            if ($list['_monitors_alerts_fired_'] > 0 && $list['_alerts_'] > 0) {
                $list['_alert_level_'] = format_numeric((100 - ($list['_monitors_alerts_fired_'] / ($list['_alerts_'] / 100))), 1);
            } else {
                $list['_alert_level_'] = 100;
            }
        } else {
            $list['_alert_level_'] = 100;
            $list['_alerts_'] = 0;
        }

        $list['_monitor_bad_'] = ($list['_monitors_critical_'] + $list['_monitors_warning_']);

        if ($list['_monitor_bad_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_global_health_'] = format_numeric((100 - ($list['_monitor_bad_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_global_health_'] = 100;
        }

        $list['_server_sanity_'] = format_numeric((100 - $list['_module_sanity_']), 1);
    } else if (($config['realtimestats'] == 0)) {
        if (users_is_admin()) {
            $group_stat = db_get_all_rows_sql(
                sprintf(
                    'SELECT
                    SUM(ta.normal_count) as normal, SUM(ta.critical_count) as critical,
                    SUM(ta.warning_count) as warning,SUM(ta.unknown_count) as unknown,
                    SUM(ta.notinit_count) as not_init, SUM(ta.fired_count) as alerts_fired
                    FROM tagente ta
                    WHERE ta.disabled = 0 AND ta.id_grupo IN (%s)
                    ',
                    $user_groups_ids
                )
            );
        } else {
            $group_stat = db_get_all_rows_sql(
                sprintf(
                    'SELECT
                    SUM(ta.normal_count) as normal, SUM(ta.critical_count) as critical,
                    SUM(ta.warning_count) as warning,SUM(ta.unknown_count) as unknown,
                    SUM(ta.notinit_count) as not_init, SUM(ta.fired_count) as alerts_fired
                    FROM tagente ta
                    LEFT JOIN tagent_secondary_group tasg
                        ON ta.id_agente = tasg.id_agent
                    WHERE ta.disabled = 0 AND
                    (ta.id_grupo IN ( %s ) OR tasg.id_group IN ( %s ))',
                    $user_groups_ids,
                    $user_groups_ids
                )
            );
        }

        $list['_agents_unknown_'] = $group_stat[0]['unknown'];
        $list['_monitors_alerts_fired_'] = $group_stat[0]['alerts_fired'];

        $list['_monitors_ok_'] = $group_stat[0]['normal'];
        $list['_monitors_warning_'] = $group_stat[0]['warning'];
        $list['_monitors_critical_'] = $group_stat[0]['critical'];
        $list['_monitors_unknown_'] = $group_stat[0]['unknown'];
        $list['_monitors_not_init_'] = $group_stat[0]['not_init'];
        $total_agentes = agents_get_agents(false, ['count(*) as total_agents'], 'AR', false, false);
        $list['_total_agents_'] = $total_agentes[0]['total_agents'];
        $list['_monitor_alerts_fire_count_'] = $group_stat[0]['alerts_fired'];

        $list['_monitors_alerts_'] = tactical_monitor_alerts($user_strict);
        // Get total count of monitors for this group, except disabled.
        $list['_monitor_checks_'] = ($list['_monitors_not_init_'] + $list['_monitors_unknown_'] + $list['_monitors_warning_'] + $list['_monitors_critical_'] + $list['_monitors_ok_']);

        // Calculate not_normal monitors
        $list['_monitor_not_normal_'] = ($list['_monitor_checks_'] - $list['_monitors_ok_']);

        if ($list['_monitor_not_normal_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_monitor_health_'] = format_numeric((100 - ($list['_monitor_not_normal_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_monitor_health_'] = 100;
        }

        if ($list['_monitors_not_init_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_module_sanity_'] = format_numeric((100 - ($list['_monitors_not_init_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_module_sanity_'] = 100;
        }

        if (isset($list['_alerts_'])) {
            if ($list['_monitors_alerts_fired_'] > 0 && $list['_alerts_'] > 0) {
                $list['_alert_level_'] = format_numeric((100 - ($list['_monitors_alerts_fired_'] / ($list['_alerts_'] / 100))), 1);
            } else {
                $list['_alert_level_'] = 100;
            }
        } else {
            $list['_alert_level_'] = 100;
            $list['_alerts_'] = 0;
        }

        $list['_monitor_bad_'] = ($list['_monitors_critical_'] + $list['_monitors_warning_']);

        if ($list['_monitor_bad_'] > 0 && $list['_monitor_checks_'] > 0) {
            $list['_global_health_'] = format_numeric((100 - ($list['_monitor_bad_'] / ($list['_monitor_checks_'] / 100))), 1);
        } else {
            $list['_global_health_'] = 100;
        }

        $list['_server_sanity_'] = format_numeric((100 - $list['_module_sanity_']), 1);
    } else {
        if (users_is_admin() || users_can_manage_group_all()) {
            $result_list = db_get_all_rows_sql(
                sprintf(
                    'SELECT COUNT(*) as contado, estado FROM tagente_estado tae 
                    INNER JOIN tagente ta
                        ON tae.id_agente = ta.id_agente
                        AND ta.disabled = 0
                        AND ta.id_grupo IN ( %s )
                    INNER JOIN tagente_modulo tam
                        ON tae.id_agente_modulo = tam.id_agente_modulo
                        AND tam.disabled = 0
                        AND tam.id_modulo <> 0
                    GROUP BY estado',
                    $user_groups_ids
                )
            );
        } else {
            $result_list = db_get_all_rows_sql(
                sprintf(
                    'SELECT COUNT(DISTINCT(tam.id_agente_modulo)) as contado, estado 
                FROM tagente_estado tae 
                    INNER JOIN tagente ta
                        ON tae.id_agente = ta.id_agente
                        AND ta.disabled = 0	
                    INNER JOIN tagente_modulo tam
                        ON tae.id_agente_modulo = tam.id_agente_modulo
                        AND tam.disabled = 0
                        AND tam.id_modulo <> 0
                    LEFT JOIN tagent_secondary_group tasg 
                        ON ta.id_agente = tasg.id_agent
                    WHERE (ta.id_grupo IN ( %s ) OR tasg.id_group IN ( %s ))
                    GROUP BY estado',
                    $user_groups_ids,
                    $user_groups_ids
                )
            );
        }

        if (empty($result_list)) {
            $result_list = [];
        }

        foreach ($result_list as $result) {
            switch ($result['estado']) {
                case AGENT_MODULE_STATUS_CRITICAL_ALERT:

                break;

                case AGENT_MODULE_STATUS_CRITICAL_BAD:
                    $list['_monitors_critical_'] += (int) $result['contado'];
                break;

                case AGENT_MODULE_STATUS_WARNING_ALERT:
                break;

                case AGENT_MODULE_STATUS_WARNING:
                    $list['_monitors_warning_'] += (int) $result['contado'];
                break;

                case AGENT_MODULE_STATUS_UNKNOWN:
                    $list['_monitors_unknown_'] += (int) $result['contado'];
                break;

                case AGENT_MODULE_STATUS_NO_DATA:
                case AGENT_MODULE_STATUS_NOT_INIT:
                    $list['_monitors_not_init_'] += (int) $result['contado'];
                break;

                case AGENT_MODULE_STATUS_NORMAL_ALERT:
                    // Do nothing.
                break;

                case AGENT_MODULE_STATUS_NORMAL:
                    $list['_monitors_ok_'] += (int) $result['contado'];
                break;
            }
        }

        $list['_monitors_alerts_fired_'] = tactical_monitor_fired_alerts(explode(',', $user_groups_ids), $user_strict, explode(',', $user_groups_ids));
        $list['_monitors_alerts_'] = tactical_monitor_alerts($user_strict);

        $total_agentes = agents_get_agents(
            ['id_grupo' => explode(',', $user_groups_ids)],
            ['count(DISTINCT id_agente) as total_agents'],
            'AR',
            false,
            false,
            1
        );
        $list['_total_agents_'] = $total_agentes[0]['total_agents'];

        $list['_monitor_checks_'] = ($list['_monitors_not_init_'] + $list['_monitors_unknown_'] + $list['_monitors_warning_'] + $list['_monitors_critical_'] + $list['_monitors_ok_']);

        $list['_monitor_total_'] = ($list['_monitors_not_init_'] + $list['_monitors_unknown_'] + $list['_monitors_warning_'] + $list['_monitors_critical_'] + $list['_monitors_ok_']);

        // Calculate not_normal monitors
        $list['_monitor_not_normal_'] = ($list['_monitor_checks_'] - $list['_monitors_ok_']);
    }

    return $list;
}


function tactical_status_modules_agents($id_user=false, $user_strict=false, $access='AR', $groups=[])
{
    global $config;

    if ($id_user === false) {
        $id_user = $config['id_user'];
    }

    if (empty($groups) === false) {
        if (is_array($groups) === false) {
            $groups = explode(',', (string) $groups);
            // Group id as key.
            $groups = array_flip($groups);
        }

        if (isset($groups[0]) === true) {
            $groups = [];
        }
    }

    $acltags = tags_get_user_groups_and_tags($id_user, $access, $user_strict);

    if (empty($groups) === false) {
        $acltags = array_intersect_key($acltags, $groups);
    }

    $result_list = tactical_get_data($id_user, $user_strict, $acltags);

    return $result_list;
}


function tactical_monitor_alerts($strict_user=false)
{
    global $config;
    $groups = users_get_groups($config['id_user'], 'AR', false);
    $id_groups = array_keys($groups);

    $where_clause = '';
    if (empty($id_groups) === true) {
        $where_clause .= ' AND (1 = 0) ';
    } else {
        $where_clause .= sprintf(
            ' AND id_agent_module IN (
            SELECT tam.id_agente_modulo
            FROM tagente_modulo tam
            WHERE tam.id_agente IN (SELECT ta.id_agente
                FROM tagente ta LEFT JOIN tagent_secondary_group tasg ON
                    ta.id_agente = tasg.id_agent
                    WHERE (ta.id_grupo IN (%s) OR tasg.id_group IN (%s)))) ',
            implode(',', $id_groups),
            implode(',', $id_groups)
        );
    }

    $filter_alert = [];
    $filter_alert['disabled'] = 'all_enabled';

    $alert_count = get_group_alerts($id_groups, $filter_alert, false, $where_clause, false, false, false, true, $strict_user);

    return $alert_count;
}


function tactical_monitor_fired_alerts($group_array, $strict_user=false, $id_group_strict=false)
{
    // If there are not groups to query, we jump to nextone
    if (empty($group_array)) {
        return 0;
    } else if (!is_array($group_array)) {
        $group_array = [$group_array];
    }

    $group_clause = implode(',', $group_array);
    $group_clause = '('.$group_clause.')';

    if ($strict_user) {
        $group_clause_strict = implode(',', $id_group_strict);
        $group_clause_strict = '('.$group_clause_strict.')';
        $sql = "SELECT COUNT(talert_template_modules.id)
		FROM talert_template_modules, tagente_modulo, tagente_estado, tagente
		WHERE tagente.id_grupo IN $group_clause_strict AND tagente_modulo.id_agente = tagente.id_agente
			AND tagente_estado.id_agente_modulo = tagente_modulo.id_agente_modulo
			AND talert_template_modules.id_agent_module = tagente_modulo.id_agente_modulo 
			AND times_fired > 0 AND talert_template_modules.disabled = 0
            AND tagente.disabled = 0 AND tagente_modulo.disabled = 0";

        $count = db_get_sql($sql);
        return $count;
    } else {
        // TODO REVIEW ORACLE AND POSTGRES
        return db_get_sql(
            "SELECT COUNT(talert_template_modules.id)
			FROM talert_template_modules, tagente_modulo, tagente_estado, tagente
			WHERE tagente.id_grupo IN $group_clause AND tagente_modulo.id_agente = tagente.id_agente
				AND tagente_estado.id_agente_modulo = tagente_modulo.id_agente_modulo
				AND talert_template_modules.id_agent_module = tagente_modulo.id_agente_modulo 
				AND times_fired > 0 AND talert_template_modules.disabled = 0
                AND tagente.disabled = 0 AND tagente_modulo.disabled = 0"
        );
    }

}
