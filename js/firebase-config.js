import { initializeApp } from
"https://www.gstatic.com/firebasejs/12.16.0/firebase-app.js";

import {
    getStorage,
    ref,
    uploadBytes,
    getDownloadURL,
    deleteObject
} from
"https://www.gstatic.com/firebasejs/12.16.0/firebase-storage.js";

const firebaseConfig = {
    apiKey: "AIzaSyABYHfaP7LbOq5YU6WHZ6cZgJ3abzpbJpw",
    authDomain: "hotel-las-3-palmeras.firebaseapp.com",
    projectId: "hotel-las-3-palmeras",
    storageBucket: "hotel-las-3-palmeras.firebasestorage.app",
    messagingSenderId: "54314692241",
    appId: "1:54314692241:web:1936b434ae44a33ce39930"
  };

const app = initializeApp(firebaseConfig);

const storage = getStorage(app);

export {
    storage,
    ref,
    uploadBytes,
    getDownloadURL,
    deleteObject
};