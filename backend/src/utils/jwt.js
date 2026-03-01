const jwt = require('jsonwebtoken');

const signToken = (id, role) =>
  jwt.sign({ id, role }, process.env.JWT_SECRET, {
    expiresIn: process.env.JWT_EXPIRES_IN || '7d',
  });

const createSendToken = (user, statusCode, res) => {
  const token = signToken(user._id, user.role);
  user.password = undefined;

  res.status(statusCode).json({
    status: 'success',
    data: {
      token,
      user: {
        _id:       user._id,
        firstName: user.firstName,
        lastName:  user.lastName,
        email:     user.email,
        role:      user.role,
      },
    },
  });
};

module.exports = { signToken, createSendToken };