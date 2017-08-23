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
 * This file contains a class definition for the Score resource
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
 * A resource implementing Score.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'Score.item';
        $this->template = '/{context_id}/lineitems/{item_id}/scores/{result_id}/score';
        $this->variables[] = 'Score.url';
        $this->formats[] = 'application/vnd.ims.lis.v1.score+json';
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
        $resultid = $params['result_id'];

        // GET is disabled by the moment, but we have the code ready
        // for a future implementation.

        $isget = $response->get_request_method() === 'GET';
        if ($isget) {
            $contenttype = $response->get_accept();
        } else {
            $contenttype = $response->get_content_type();
        }

        try {
            if (!$this->check_tool_proxy(null, $response->get_request_data())) {
                throw new \Exception(null, 401);
            }
            if (empty($contextid) || (!empty($contenttype) && !in_array($contenttype, $this->formats))) {
                throw new \Exception(null, 400);
            }
            if (($item = $this->get_service()->get_lineitem($contextid, $itemid, true)) === false) {
                throw new \Exception(null, 400);
            }

            require_once($CFG->libdir.'/gradelib.php');

            $grade = \grade_grade::fetch(array('itemid' => $itemid, 'userid' => $resultid));
            if ($grade === false) {
                throw new \Exception(null, 400);
            }
            switch ($response->get_request_method()) {
                case 'GET':
                    // $response->set_content_type($this->formats[0]);
                    // $json = $this->get_request_json($grade);
                    // $response->set_body($json);
                    $response->set_code(405);
                    break;
                case 'PUT':
                    $json = $this->put_request($response->get_request_data(), $item, $resultid);
                    $response->set_body($json);
                    $response->set_code(200);
                    break;
                case 'DELETE':
                    $this->delete_request($item, $resultid);
                    $response->set_code(200);
                    break;
                default:  // Should not be possible.
                    throw new \Exception(null, 405);
            }

        } catch (\Exception $e) {
            $response->set_code($e->getCode());
        }

    }

    // This code is not used because the GET is disabled, but it
    // stays here to make easier a future implementation.

    /**
     * Generate the JSON for a GET request.
     *
     * @param object $grade       Grade instance
     *
     * return string
     */
    private function get_request_json($grade) {

        if (empty($grade->timemodified)) {
            throw new \Exception(null, 400);
        }
        $lineitem = new lineitem($this->get_service());
        $json = gradebookservices::score_to_json($grade, $lineitem->get_endpoint(), true);

        return $json;

    }


    /**
     * Process a PUT request.
     *
     * @param string $body        PUT body
     * @param object $item        Lineitem instance
     * @param string $userid      User ID
     */
    private function put_request($body, $item, $userid) {

        $score = json_decode($body);
        if (empty($score) || !isset($score->{"@type"}) || ($score->{"@type"} != 'Score') ||
        (isset($score->resultAgent) && isset($score->resultAgent->userId) && ($score->resultAgent->userId !== $userid)) ||
        (!isset($score->scoreGiven))||(!isset($score->gradingProgress))) {
            throw new \Exception(null, 400);
        }
        if ($score->gradingProgress == "FullyGraded") {
            gradebookservices::set_grade_item($item, $score, $userid);
        } else {
            $this->delete_request($item, $score->resultAgent->userId);
        }
        $lineitem = new lineitem($this->get_service());
        $endpoint = $lineitem->get_endpoint();
        $endpoint = substr($endpoint, 0, strripos($endpoint, '/'));
        $id = "{$endpoint}/scores/{$score->resultAgent->userId}";
        $score->{"@id"} = $id;
        return json_encode($score);

    }


    /**
     * Process a DELETE request.
     *
     * @param object $item       Lineitem instance
     * @param string  $userid    User ID
     */
    private function delete_request($item, $userid) {

        $grade = new \stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        $grade->feedback = null;
        $grade->feedbackformat = FORMAT_MOODLE;
        $status = grade_update('mod/ltiservice_gradebookservices', $item->courseid, $item->itemtype, $item->itemmodule,
                               $item->iteminstance, $item->itemnumber, $grade);
        if ($status !== GRADE_UPDATE_OK) {
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
        global $COURSE, $USER, $CFG;

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
            $this->params['result_id'] = $USER->id;

            $value = str_replace('$Score.url', parent::get_endpoint(), $value);

            return $value;
        } else {
            return '';
        }

    }

}
