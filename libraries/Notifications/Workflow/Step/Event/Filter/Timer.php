<?php
/**
 * @package     PublishPress\Notifications
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2018 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

namespace PublishPress\Notifications\Workflow\Step\Event\Filter;

class Timer extends Base implements Filter_Interface
{

    const META_KEY_POST_STATUS_TRIGGER = '_psppno_pubtrigger';

    const META_KEY_POST_STATUS_AMOUNT = '_psppno_pubamount';

    const META_KEY_POST_STATUS_UNIT = '_psppno_pubunit';

    /**
     * Function to render and returnt the HTML markup for the
     * Field in the form.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function render()
    {
        echo $this->get_service('twig')->render(
            'workflow_filter_timer.twig',
            [
                'name'   => "publishpress_notif[{$this->step_name}_filters][timer]",
                'id'     => "publishpress_notif_{$this->step_name}_filters_timer",
                'labels' => [
                    'trigger' => esc_html__('Trigger', 'publishpress'),
                    'amount'  => esc_html__('Amount', 'publishpress'),
                    'before'  => esc_html__('Before', 'publishpress'),
                    'after'   => esc_html__('After', 'publishpress'),
                    'unit'    => esc_html__('Unit of timer', 'publishpress'),
                    'hour'    => esc_html__('Hours', 'publishpress'),
                    'day'     => esc_html__('Days', 'publishpress'),
                    'week'    => esc_html__('Weeks', 'publishpress'),
                ],
                'values' => [
                    'trigger' => $this->get_metadata(static::META_KEY_POST_STATUS_TRIGGER, true),
                    'amount'  => $this->get_metadata(static::META_KEY_POST_STATUS_AMOUNT, true),
                    'unit'    => $this->get_metadata(static::META_KEY_POST_STATUS_UNIT, true),
                ],
            ]
        );
    }

    /**
     * Function to save the metadata from the metabox
     *
     * @param int     $id
     * @param WP_Post $post
     */
    public function save_metabox_data($id, $post)
    {
        // Trigger
        if (!isset($_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['trigger'])) {
            $trigger = 'before';
        } else {
            $trigger = $_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['trigger'];
        }
        $trigger = [$trigger];

        $this->update_metadata_array($id, static::META_KEY_POST_STATUS_TRIGGER, $trigger);

        // Amount
        if (!isset($_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['amount'])) {
            $amount = '1';
        } else {
            $amount = $_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['amount'];
        }
        $amount = [$amount];

        $this->update_metadata_array($id, static::META_KEY_POST_STATUS_AMOUNT, $amount);

        // Unit
        if (!isset($_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['unit'])) {
            $unit = 'hour';
        } else {
            $unit = $_POST['publishpress_notif']["{$this->step_name}_filters"]['timer']['unit'];
        }
        $unit = [$unit];

        $this->update_metadata_array($id, static::META_KEY_POST_STATUS_UNIT, $unit);
    }

    /**
     * Filters and returns the arguments for the query which locates
     * workflows that should be executed.
     *
     * @param array $query_args
     * @param array $action_args
     * @return array
     */
    public function get_run_workflow_query_args($query_args, $action_args)
    {
        // Publishing
        $query_args['meta_query'][] = [
            [
                'key'     => 'publish',
                'value'   => $action_args['new_status'],
                'type'    => 'CHAR',
                'compare' => '=',
            ],
        ];



        return parent::get_run_workflow_query_args($query_args, $action_args);
    }
}
