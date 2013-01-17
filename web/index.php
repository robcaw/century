<?php

require_once __DIR__.'/../vendor/autoload.php';

use Igorw\Silex\ConfigServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Knp\Provider\RepositoryServiceProvider;
use Century\Provider\UserProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
//use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Endurance\Strava\StravaClient;
use Symfony\Component\Validator\Constraints as Assert;


// Do I need these here:
use Buzz\Browser;
use Buzz\Message\Form\FormRequest;
use Buzz\Message\Response;
use Buzz\Util\Url;
use Buzz\Client\Curl;

$app = new Silex\Application();

$app->register(new ConfigServiceProvider(__DIR__ . '/../config/config.yml'));

$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new FormServiceProvider());
$app->register(new TranslationServiceProvider(), array(
    'locale_fallback' => 'en',
));
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new TwigServiceProvider(), array(
      'twig.path'       => __DIR__ . '/../views',
));
$app->register(new DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver' => 'pdo_mysql',
        'host' => $app['db.host'],
        'dbname' => $app['db.name'],
        'username' => $app['db.username'],
        'password' => $app['db.password']
    )
));
$app->register(new RepositoryServiceProvider(), array('repository.repositories' => array(
    'rides'      => 'Century\\Repository\\RideRepo',
    'users'      => 'Century\\Repository\\UserRepo'
)));
$app->register(new Silex\Provider\SecurityServiceProvider(), array(
    'security.firewalls' => array( 
        'add' => array(
            'pattern' => '^/add',
            'form' => array('login_path' => '/login', 'check_path' => '/add/login_check'),
            'logout' => array('logout_path' => '/add/logout'),
            'users' => $app->share(function() use ($app) {
                // raw password is foo
                return new Century\Provider\UserProvider($app['db']);
            })
            
        ),
    ),
));


$app['debug'] = true;


$app->get('/', function () use ($app) {
    //Show leaderboard and latest rides
    $rides = $app['rides']->getAllRides();
    $users = $app['users']->getAllUsers(false);

    $year = (int) date('Y');
    $months = array();
    foreach (range(1, (int) date('n')) as $month) {
         $months[$month] = array(
            'date' => date('F', mktime(0, 0, 0, $month)),
           
            'rides' => $app['rides']->getAllRides(null, $month, $year)
        );
    }



    return $app['twig']->render('index.html.twig', array(
        'users' => $users,
        'rides' => $rides,
        'months' => $months,
        'year' => $year,
        'userRepo' => $app['users']
    ));
});

$app->get('/rides', function () use ($app) {
 return 'he';
});

$app->get('/rides/{username}', function ($username) use ($app) {
    //Show Rides for specific user
   
    $user = $app['users']->getUserByUsername($username);
    
    if($user == null){
        throw new \InvalidArgumentException('User does not exist');
    }

    $months = array();
    $year = (int) date('Y');
    foreach (range((int) date('n'), 1) as $month) {
        $months[$month] = array(
            'date' => date('F', mktime(0, 0, 0, $month)),
           
            'rides' => $user->getRides($month, $year)
        );

    }

    return $app['twig']->render('rides.html.twig', array(
        'user' => $user,
        'months' => $months,
        'userRepo' => $app['users']
    ));
});

$app->get('/ride/{ride_id}', function ($ride_id) use ($app) {
    //Show Rides for specific user
    $ride = $app['rides']->getRideById($ride_id);

    $user = $app['users']->getUserById($ride->getUserId());

    

    $strava_details = $strava->getRideDetails($ride->getStravaRideId());
    if($ride == null ){
        throw new \InvalidArgumentException('Ride does not exist');
    }

    return $app['twig']->render('ride_single.html.twig', array(
        'user' => $user,
        'ride' => $ride,
        'strava' => $strava_details
    ));
});

$app->match('/add', function () use ($app) {
    

    if ($app['request']->getMethod() === 'POST') {
        $return_data = array('return_form_data' => $app['request']->get('return_form_data'),
                             'errors' => $app['request']->get('errors')
                             );
    }
    else{
        $return_data = null;
    }

    $form = $app['form.factory']->createBuilder('form')
            ->add('date', 'text', array(
                'label' => 'Date of ride',
                'required' => false
            ))
            ->add('km', 'text', array(
                'label' => 'Distance',
                'required' => false
            ))
            ->add('average_speed', 'text', array(
                'label' => 'Average Speed',
                'required' => false
            ))
            ->add('url', 'text', array(
                'label' => 'Link to ride',
                'required' => false
            ))
            ->add('details', 'textarea', array(
                'label' => 'Notes',
                'required' => false
            ))
            ->getForm();

    $form_strava = $app['form.factory']->createBuilder('form', $return_data['return_form_data'])
            ->add('strava_ride_id', 'text', array(
                'required' => false
            ))
            ->getForm();

    return $app['twig']->render('add2.html.twig', array('form' => $form->createView(), 
                                                        'form_strava' => $form_strava->createView(), 
                                                        'errors' => $return_data['errors'],
                                                        'return_form_data' => $return_data['return_form_data']
                                                        ));

});

$app->post('/add/manual', function () use ($app) {

   
});

$app->post('/add/strava', function (Request $request) use ($app) {
    
    $token = $app['security']->getToken();
    if (null !== $token) {
        $user = $token->getUser();

    }
    $user_id = $user->getUserId();

    $data = $request->get('form');
    $errors = $app['validator']->validateValue($data['strava_ride_id'], new Assert\MinLength(8));
   
    if (count($errors) > 0) {
        $return_data = array(
            'return_form_data' => $data,
            'errors' => $errors
        );
        $subRequest = Request::create('/add', 'POST', $return_data);
        return $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        
    }
    else {
        $browser = new Browser(new Curl());
        $client = new StravaClient($browser);
        $ride_details = $client->getRideDetails($data['strava_ride_id']);

        
        if(isset($ride_details['ride']['id'])){
            $km = round($ride_details['ride']['distance'] / 1000, 1);
            $url = 'http://app.strava.com/activities/'. (string) $ride_details['id'];
            $date = \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $ride_details['ride']['start_date_local']);
            $converted_date = $date->format('Y-m-d');
            $average_speed = $ride_details['ride']['average_speed'];
            $strava_ride_id = $ride_details['id'];

            $prepared_data = array('user_id' => $user_id,
                                   'km' => $km,
                                   'url' => $url,
                                   'date' => $converted_date,
                                   'average_speed' => $average_speed,
                                   'strava_ride_id' => $strava_ride_id
                                    );
            $app['rides']->insert($prepared_data);
            return $app['twig']->render('success.html.twig', array('message' => 'Your ride was added successfully'
                                                               ));
        }
        else{
            throw new \InvalidArgumentException('The ride ID is invalid');
        }
    }

});

$app->get('/ride/{$id}', function () use ($app) {
    //Show a single ride by its ID
});

$app->match('/register', function () use ($app) {
    //User registration

     $form = $app['form.factory']->createBuilder('form')
        ->add('username', 'text', array(
            'label' => 'Username',
            'required' => true
        ))
        ->add('name', 'text', array(
            'label' => 'Full name',
            'required' => false
        ))
        ->add('email', 'text', array(
            'label' => 'E-mail address',
            'required' => true
        ))
        ->add('password', 'text', array(
            'label' => 'Password',
            'required' => true
        ))
        ->add('forum_name', 'text', array(
            'label' => 'LFCC forum username',
            'required' => true
        ))
        ->add('strava', 'text', array(
            'label' => 'Strava athlete ID',
            'required' => true
        ))
        ->getForm();

    if ($app['request']->getMethod() === 'POST') {
        $form->bind($app['request']);
        if ($form->isValid()) {
            //get form data
            $data = $form->getData();

            //encode password
            $password = $app['security.encoder.digest']->encodePassword($data['password'], strtolower($data['username']));

           
            $app['users']->insert(array(
                'username'  => strtolower($data['username']),
                'password'  => $password,
                'roles'     => 'ROLE_USER',
                'email'     => $data['email'],
                'name'      => $data['name'],
                'forum_name'=> $data['forum_name'],
                'strava'    => $data['strava']
            ));
            

            return $app->redirect('/');
        }
    }

    return $app['twig']->render('register.html.twig', array('form' => $form->createView()));
});

$app->match('/login', function(Request $request) use ($app) {
    return $app['twig']->render('login.html.twig', array(
        'error'         => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ));
});




$app->run();
