<?php
/**
 * @package     PublishPress\Notifications
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2018 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

namespace PublishPress\Notifications\Workflow\Step\Event;

use PublishPress\Notifications\Traits\Dependency_Injector;
use PublishPress\Notifications\Workflow\Step\Event\Filter;

class Publishing extends Base
{

    const META_KEY_SELECTED = '_psppno_evtpublishing';

    const META_VALUE_SELECTED = 'publishing';

    /**
     * The constructor
     */
    public function __construct()
    {
        $this->name  = 'publishing';
        $this->label = __('Before or after the content is published', 'publishpress');

        parent::__construct();

        // Add filter to return the metakey representing if it is selected or not
        add_filter('psppno_events_metakeys', [$this, 'filter_events_metakeys']);
    }

    /**
     * Method to return a list of fields to display in the filter area
     *
     * @param array
     *
     * @return array
     */
    protected function get_filters($filters = [])
    {
        if (!empty($this->cache_filters))
        {
            return $this->cache_filters;
        }

        $step_name = $this->attr_prefix . '_' . $this->name;

        $filters[] = new Filter\Timer($step_name);

        return parent::get_filters($filters);
    }

    /**
     * Filters and returns the arguments for the query which locates
     * workflows that should be executed.
     *
     * @param array $query_args
     * @param array $action_args
     * @return array
     */
    public function filter_run_workflow_query_args($query_args, $action_args)
    {

        if ('publishing_reminder' === $action_args['action'])
        {
            $query_args['meta_query'][] = [
                'key'     => static::META_KEY_SELECTED,
                'value'   => 1,
                'type'    => 'BOOL',
                'compare' => '=',
            ];

            // Check the filters
            $filters = $this->get_filters();

            foreach ($filters as $filter)
            {
                $query_args = $filter->get_run_workflow_query_args($query_args, $action_args);
            }
        }

        return $query_args;
    }
}
