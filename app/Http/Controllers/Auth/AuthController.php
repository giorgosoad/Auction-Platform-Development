<?php

namespace App\Http\Controllers\Auth;

use App\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;


class AuthController extends Controller
{  

    public function login()
    {
        if ( Auth::check() ){
            return redirect()->route('profile');
        }
        return view('auth.login');
    }


    public function process_login(Request $request)
    {
        if ( Auth::check() ){
            return redirect()->route('profile');
        }
        $rules = [
            'username' => 'required',
            'password' => 'required'
        ];
        $validator = Validator::make($request->except(['_token']), $rules);
        if ( $validator->fails() ){
            return Redirect::to('login')->withErrors($validator);
        }

        $username = $request->input('username');
        $password = $request->input('password');

        $credentials = [
            'username' => $username,
            'password' => $password,
        ];
        #dd($credentials);
        if ( Auth::attempt($credentials) ){
            if ( Auth::user()->role == 'admin' ){
                return redirect()->route('products');
            } else{
                return redirect()->route('profile');
            }
        } else{
            $request->session()->flash('error_msg', 'Λάθος στοιχεία εισόδου.');
            return redirect()->back();
        }
    }


    public function register()
    {
        if ( Auth::check() ){
            return redirect()->route('profile');
        }
        return view('auth.register');
    }


    public function process_register(Request $request)
    {   
        if ( Auth::check() ){
            return redirect()->route('profile');
        }

        $customMessages = [
            'unique' => 'Το όνομα χρήστη υπάρχει ήδη. Επιλέξτε κάποιο άλλο.'
        ];

        $request->validate([
            'username' => 'required|unique:users',
            'password' => 'required|min:6'
        ], $customMessages);

        $username = trim($request->input('username'));

        $user = User::create([
            'username' => $username,
            'role' => 'customer',
            'password' => Hash::make($request->input('password')),
        ]);

        if ( !empty($user) ){
            $request->session()->flash('success_msg', 'Ο λογαριασμός δημιουργήθηκε με επιτυχία. Μπορείτε να πραγματοποιήσετε είσοδο.');
            return redirect()->route('login');
        }
        return redirect()->route('auth.register');
    }


    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }


    public function profile(Request $request)
    {
        if ( !Auth::check() ){
            $request->session()->flash('error_msg', 'Πραγματοποιήστε είσοδο πρώτα.');
            return redirect()->route('login');
        }
        $user = User::where('id', Auth::user()->id)->first();

        $method = $request->method();
        if ( $method == 'POST' ){
            $user->fullname = $request->input('fullname');
            $user->address = $request->input('address');
            $user->tel = $request->input('tel');
    
            $user->card_number = $request->input('card_number');
            $user->card_holder = $request->input('card_holder');
            $user->card_expiry = $request->input('card_expiry');
            $user->save();
            $request->session()->flash('success_msg', 'Επιτυχής αποθήκευση.');
        }
        return view('auth.profile', [
            'user' => $user,
        ]);
    }
}