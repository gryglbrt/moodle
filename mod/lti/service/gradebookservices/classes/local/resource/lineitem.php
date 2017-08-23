<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains a class definition for the LineItem resource
 *
 * @package    ltiservice_gradebookservices
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @author     Dirk Singels
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace ltiservice_gradebookservices\local\resource;

use ltiservice_gradebookservices\local\service\gradebookservices;

defined('MOODLE_INTERNAL') || die();

/**
 * A resource implementing LineItem.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lineitem extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'LineItem.item';
        $this->template = '/{context_id}/lineitems/{item_id}/lineitem';
        $this->variables[] = 'LineItem.url';
        $this->formats[] = 'application/vnd.ims.lis.v2.lineitem+json';
        $this->methods[] = 'GET';
        $this->methods[] = 'PUT';
        $this->methods[] = 'DELETE';

    }

    /**
     * Execute the request for this resource.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     */
    public function execute($response) {
        global $CFG;

        $params = $this->parse_template();
        $contextid = $params['context_id'];
        $itemid = $params['item_id'];
        if ($response->get_request_method() === 'GET') {
            $contenttype = $response->get_accept();
        } else {
            $contenttype = $response->get_content_type();
        }
        $isdelete = $response->get_request_method() === 'DELETE';

        try {
            if (!$this->check_tool_proxy(null, $response->get_request_data())) {
                throw new \Exception(null, 401);
            }
            if (empty($contextid) || (!empty($contenttype) && !in_array($contenttype, $this->formats))) {
                throw new \Exception(null, 400);
            }
            if (($item = $this->get_service()->get_lineitem($contextid, $itemid, !$isdelete)) === false) {
                throw new \Exception(null, 400);
            }
            require_once($CFG->libdir.'/gradelib.php');
            switch ($response->get_request_method()) {
                case 'GET':
                    $this->get_request($response, $contextid, $item);
                    break;
                case 'PUT':
                    $this->put_request($response->get_request_data(), $item);
                    break;
                case 'DELETE':
                    $this->delete_request($item);
                    break;
                default:  // Should not be possible.
                    throw new \Exception(null, 405);
            }

        } catch (\Exception $e) {
            $response->set_code($e->getCode());
        }

    }

    /**
     * Process a GET request.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     * @param boolean $results   True if results are to be included in the response.
     * @param string  $item       Grade item instance.
     */
    private function get_request($response, $contextid, $item) {

        $response->set_content_type($this->formats[0]);
        $json = gradebookservices::item_to_json($item, parent::get_endpoint(), false, $contextid);
        $response->set_body($json);

    }

    /**
     * Process a PUT request.
     *
     * @param string $body       PUT body
     * @param string $olditem    Grade item instance
     */
    private function put_request($body, $olditem) {

        $json = json_decode($body);
        if (empty($json) || !isset($json->{"@type"}) || ($json->{"@type"} != 'LineItem')) {
            throw new \Exception(null, 400);
        }
        $item = \grade_item::fetch(array('id' => $olditem->id, 'courseid' => $olditem->courseid));
        $updategradeitem = false;
        if (isset($json->label) && ($item->itemname !== $json->label)) {
            $item->itemname = $json->label;
            $updategradeitem = true;
        }
        if (isset($json->lineItemScoreMaximum) &&
                grade_floats_different(grade_floatval($item->grademax),
                        grade_floatval($json->lineItemScoreMaximum))) {
            $item->grademax = grade_floatval($json->lineItemScoreMaximum);
            $updategradeitem = true;
        }
        if (isset($json->resourceId) && ($item->idnumber !== $json->resourceId)) {
            $item->idnumber = $json->resourceId;
            $updategradeitem = true;
        }
        if (isset($json->resourceLinkId) && is_numeric($json->resourceLinkId) &&
                intval($item->iteminstance) !== intval($json->resourceLinkId)) {
            $item->iteminstance = intval($json->resourceLinkId);
            $updategradeitem = true;
        }
        if ($updategradeitem) {
            if (!$item->update('mod/ltiservice_gradebookservices')) {
                throw new \Exception(null, 500);
            }
        }

    }

    /**
     * Process a DELETE request.
     *
     * @param string $item       Grade item instance
     */
    private function delete_request($item) {

        $gradeitem = \grade_item::fetch(array('id' => $item->id, 'courseid' => $item->courseid));
        if (!$gradeitem->delete('mod/ltiservice_gradebookservices')) {
            throw new \Exception(null, 500);
        }

    }

    /**
     * Parse a value for custom parameter substitution variables.
     *
     * @param string $value String to be parsed
     *
     * @return string
     */
    public function parse_value($value) {
        global $COURSE, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = grade_get_grades($COURSE->id, 'mod', 'lti', $id);
        if ($item) {
            $this->params['item_id'] = $item->items[0]->id;
            $this->params['context_id'] = $COURSE->id;
            $id = optional_param('id', 0, PARAM_INT); // Course Module ID.
            if (!empty($id)) {
                $cm = get_coursemodule_from_id('lti', $id, 0, false);
                if ($cm) {
                    $id = $cm->instance;
                }
            }
            $value = str_replace('$LineItem.url', parent::get_endpoint(), $value);

            return $value;
        } else {
            return '';
        }

    }

}
