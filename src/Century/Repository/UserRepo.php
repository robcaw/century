<?php

namespace Century\Repository;

use Knp\Repository;
use Century\User;
use Century\Ride;

class UserRepo Extends Repository
{
	public function getTableName()
    {
        return 'user';
    }
    public function getAllUsers($sort_by_points = true, $disqualified = false)
    {
	    $sql_users = 'SELECT * FROM user';
    	$result_users = $this->db->fetchAll($sql_users);
        
        $sql_rides = 'SELECT * FROM ride' ;   
        $result_rides = $this->db->fetchAll($sql_rides);
       

        $users = array();

        foreach($result_users as $u){
            $rides = array();
            foreach($result_rides as $r){
                if($r['user_id'] == $u['user_id']){
                    $ride = new Ride($r['ride_id'], $r['user_id'], $r['km'], $r['url'], \DateTime::createFromFormat('Y-m-d H:i:s',$r['date']), $r['details'], $r['average_speed'], $r['strava_ride_id']);
                    $rides[] = $ride;
                }
            }   


        	$user = new User($u['user_id'], $u['username'], $u['password'], explode(',', $u['roles']), $u['email'], $u['name'], $u['forum_name'], $u['strava'], $rides, $u['metric']);
       		$users[] = $user;
        }

        /*if($sort_by_points){
            usort($users, function($b, $a){
                return strcmp($a->getPoints(), $b->getPoints());
            });
        }*/
        if($disqualified){
            $allowed_users = array();
            foreach($users as $user){
                if(!$user->isDisqualified())
                    $allowed_users[] = $user;
            }
            $users = $allowed_users;
        }

        if($sort_by_points){
            $points = array();
            foreach ($users as $key => $row)
            {
                $points[$key] = $row->getPoints();
            }
            array_multisort($points, SORT_DESC, $users);
        }
        return $users;
    }

    public function getUserByUsername($username)
    {
        $users = $this->getAllUsers();

        $user = null;
        foreach($users as $u){
            if($u->getUsername() == $username){
                $user = $u;
            }
        }
        return $user;
    }
    public function getUserById($user_id)
    {
        $users = $this->getAllUsers();

        $user = null;
        foreach($users as $u){
            if($u->getUserId() == $user_id){
                $user = $u;
            }
        }
        return $user;
    }
    public function getLatest()
    {

    }
    public function getDisqualifiedUsers(){
       $users =  $this->getAllUsers(true);
       $disqualified_users = array();
       foreach($users as $u){
            if($u->isDisqualified())
                $disqualified_users[] = $u;
       }

       return $disqualified_users;
    }

}